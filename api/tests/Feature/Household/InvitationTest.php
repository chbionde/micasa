<?php

use App\Actions\Invitations\AcceptInvitation;
use App\Actions\Invitations\CreateInvitation;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function casaComAdmin(): array
{
    $admin = User::factory()->create();
    $casa = Household::factory()->create();
    $casa->members()->attach($admin->id, ['role' => HouseholdRole::Admin->value]);

    return [$casa, $admin];
}

function conviteEmClaro(Household $casa, User $criador, HouseholdRole $papel = HouseholdRole::Member): string
{
    return app(CreateInvitation::class)->handle($casa, $criador, $papel)['token'];
}

// ---------------------------------------------------------------- criação

it('admin gera convite e recebe o token uma única vez', function () {
    [$casa, $admin] = casaComAdmin();

    $response = $this->actingAs($admin)
        ->postJson("/api/households/{$casa->id}/invitations")
        ->assertCreated();

    $token = $response->json('token');

    expect($token)->toBeString()->toHaveLength(40)
        // O banco guarda só o hash: o token em claro não está lá.
        ->and(Invitation::sole()->token_hash)->not->toBe($token)
        ->and(Invitation::sole()->token_hash)->toBe(hash('sha256', $token));

    // A listagem nunca devolve o token nem o hash.
    $listagem = $this->getJson("/api/households/{$casa->id}/invitations")->json('data.0');
    expect($listagem)->not->toHaveKey('token')
        ->and($listagem)->not->toHaveKey('token_hash')
        ->and($listagem['situacao'])->toBe('pendente');
});

it('membro comum não gera convite', function () {
    [$casa, $admin] = casaComAdmin();
    $membro = User::factory()->create();
    $casa->members()->attach($membro->id, ['role' => HouseholdRole::Member->value]);

    $this->actingAs($membro)
        ->postJson("/api/households/{$casa->id}/invitations")
        ->assertForbidden();

    expect(Invitation::count())->toBe(0);
});

it('estranho não gera nem lista convites de casa alheia', function () {
    [$casa] = casaComAdmin();
    $estranho = User::factory()->create();

    $this->actingAs($estranho)
        ->postJson("/api/households/{$casa->id}/invitations")
        ->assertForbidden();

    $this->actingAs($estranho)
        ->getJson("/api/households/{$casa->id}/invitations")
        ->assertForbidden();
});

it('exige autenticação para gerar convite', function () {
    [$casa] = casaComAdmin();

    $this->postJson("/api/households/{$casa->id}/invitations")->assertUnauthorized();
});

it('responde 403 antes de validar o corpo para quem não pode convidar', function () {
    [$casa] = casaComAdmin();
    $estranho = User::factory()->create();

    // Corpo inválido de propósito: a resposta precisa ser 403 (não pode),
    // não 422 (campo errado) — senão revelamos que o corpo foi analisado.
    $this->actingAs($estranho)
        ->postJson("/api/households/{$casa->id}/invitations", ['papel' => 'dono'])
        ->assertForbidden();
});

it('rejeita papel inválido', function () {
    [$casa, $admin] = casaComAdmin();

    $this->actingAs($admin)
        ->postJson("/api/households/{$casa->id}/invitations", ['papel' => 'dono'])
        ->assertInvalid(['papel']);
});

// ----------------------------------------------------------------- aceite

it('aceita convite válido, entra na casa e passa a vê-la como ativa', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);
    $convidado = User::factory()->create();

    $this->actingAs($convidado)
        ->postJson("/api/invitations/{$token}/accept")
        ->assertOk()
        ->assertJsonPath('casa.nome', $casa->name);

    $convidado->refresh();

    expect($convidado->isMemberOf($casa))->toBeTrue()
        ->and($convidado->isAdminOf($casa))->toBeFalse()
        ->and($convidado->active_household_id)->toBe($casa->id)
        ->and(Invitation::sole()->accepted_by)->toBe($convidado->id);
});

it('respeita o papel gravado no convite', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin, HouseholdRole::Admin);
    $convidado = User::factory()->create();

    $this->actingAs($convidado)->postJson("/api/invitations/{$token}/accept")->assertOk();

    expect($convidado->isAdminOf($casa))->toBeTrue();
});

it('recusa token inexistente', function () {
    $this->actingAs(User::factory()->create())
        ->postJson('/api/invitations/token-inventado/accept')
        ->assertInvalid(['token']);
});

it('recusa convite expirado', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);
    Invitation::sole()->update(['expires_at' => now()->subMinute()]);
    $convidado = User::factory()->create();

    $this->actingAs($convidado)
        ->postJson("/api/invitations/{$token}/accept")
        ->assertInvalid(['token']);

    expect($convidado->fresh()->isMemberOf($casa))->toBeFalse();
});

it('recusa convite revogado', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);

    $this->actingAs($admin)
        ->deleteJson("/api/households/{$casa->id}/invitations/".Invitation::sole()->id)
        ->assertOk()
        ->assertJsonPath('data.situacao', 'revogado');

    $this->actingAs(User::factory()->create())
        ->postJson("/api/invitations/{$token}/accept")
        ->assertInvalid(['token']);
});

it('não deixa o mesmo convite ser usado duas vezes', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);

    $this->actingAs(User::factory()->create())
        ->postJson("/api/invitations/{$token}/accept")
        ->assertOk();

    $segundo = User::factory()->create();
    $this->actingAs($segundo)
        ->postJson("/api/invitations/{$token}/accept")
        ->assertInvalid(['token']);

    expect($segundo->fresh()->isMemberOf($casa))->toBeFalse()
        ->and($casa->members()->count())->toBe(2);
});

it('recusa o aceite se o convite for consumido entre a leitura e a escrita', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);
    $primeiro = User::factory()->create();
    $segundo = User::factory()->create();

    // Simula a corrida: no instante em que a action carrega o convite,
    // outra requisição já o consumiu. Sem a reivindicação atômica, os dois
    // entrariam na casa com o mesmo link.
    Invitation::retrieved(function (Invitation $invitation) use ($primeiro) {
        if ($invitation->accepted_at === null) {
            DB::table('invitations')->where('id', $invitation->id)->update([
                'accepted_at' => now(),
                'accepted_by' => $primeiro->id,
            ]);
        }
    });

    expect(fn () => app(AcceptInvitation::class)->handle($token, $segundo))
        ->toThrow(ValidationException::class);

    expect($segundo->fresh()->isMemberOf($casa))->toBeFalse()
        ->and($casa->members()->count())->toBe(1);
});

it('avisa quem já é membro em vez de duplicar o vínculo', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);

    $this->actingAs($admin)
        ->postJson("/api/invitations/{$token}/accept")
        ->assertInvalid(['token']);

    expect($casa->members()->count())->toBe(1)
        ->and(Invitation::sole()->accepted_at)->toBeNull();
});

it('exige autenticação para aceitar convite', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);

    $this->postJson("/api/invitations/{$token}/accept")->assertUnauthorized();
});

// --------------------------------------------------------------- revogação

it('membro comum não revoga convite', function () {
    [$casa, $admin] = casaComAdmin();
    conviteEmClaro($casa, $admin);
    $membro = User::factory()->create();
    $casa->members()->attach($membro->id, ['role' => HouseholdRole::Member->value]);

    $this->actingAs($membro)
        ->deleteJson("/api/households/{$casa->id}/invitations/".Invitation::sole()->id)
        ->assertForbidden();

    expect(Invitation::sole()->revoked_at)->toBeNull();
});

it('não revoga convite de outra casa pela URL da própria casa', function () {
    [$minhaCasa, $eu] = casaComAdmin();
    [$outraCasa, $outroAdmin] = casaComAdmin();
    conviteEmClaro($outraCasa, $outroAdmin);
    $conviteAlheio = Invitation::sole();

    // scopeBindings: o convite não pertence à casa da URL, então 404.
    $this->actingAs($eu)
        ->deleteJson("/api/households/{$minhaCasa->id}/invitations/{$conviteAlheio->id}")
        ->assertNotFound();

    expect($conviteAlheio->fresh()->revoked_at)->toBeNull();
});

it('não revoga convite já aceito', function () {
    [$casa, $admin] = casaComAdmin();
    $token = conviteEmClaro($casa, $admin);
    $this->actingAs(User::factory()->create())->postJson("/api/invitations/{$token}/accept");

    $this->actingAs($admin)
        ->deleteJson("/api/households/{$casa->id}/invitations/".Invitation::sole()->id)
        ->assertInvalid(['invitation']);
});
