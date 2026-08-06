<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;

function casaCom(User $admin, ?User $membro = null): Household
{
    $casa = Household::factory()->create();
    $casa->members()->attach($admin->id, ['role' => HouseholdRole::Admin->value]);

    if ($membro !== null) {
        $casa->members()->attach($membro->id, ['role' => HouseholdRole::Member->value]);
    }

    return $casa;
}

/**
 * active_household_id fica fora do #[Fillable] de propósito — não pode vir
 * de input do usuário —, então aqui atribuímos direto.
 */
function definirCasaAtiva(User $user, Household $household): void
{
    $user->active_household_id = $household->id;
    $user->save();
}

// ------------------------------------------------------------ minhas casas

it('lista apenas as casas de quem pediu', function () {
    $eu = User::factory()->create();
    $minhaCasa = casaCom($eu);
    casaCom(User::factory()->create()); // casa alheia

    $this->actingAs($eu)
        ->getJson('/api/households')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $minhaCasa->id)
        ->assertJsonPath('data.0.meu_papel', 'admin');
});

it('devolve a casa ativa junto do usuário', function () {
    $eu = User::factory()->create();
    $casa = casaCom($eu);
    definirCasaAtiva($eu, $casa);

    $this->actingAs($eu)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('data.casa_ativa.id', $casa->id)
        ->assertJsonPath('data.casa_ativa.meu_papel', 'admin');
});

// ---------------------------------------------------------- troca de casa

it('troca a casa ativa entre casas de que participo', function () {
    $eu = User::factory()->create();
    $casaA = casaCom($eu);
    $casaB = Household::factory()->create();
    $casaB->members()->attach($eu->id, ['role' => HouseholdRole::Member->value]);
    definirCasaAtiva($eu, $casaA);

    $this->actingAs($eu)
        ->putJson('/api/user/active-household', ['household_id' => $casaB->id])
        ->assertOk()
        ->assertJsonPath('data.casa_ativa.id', $casaB->id);

    expect($eu->fresh()->active_household_id)->toBe($casaB->id);
});

it('não deixa trocar para casa de que não participo', function () {
    $eu = User::factory()->create();
    $minhaCasa = casaCom($eu);
    $casaAlheia = casaCom(User::factory()->create());
    definirCasaAtiva($eu, $minhaCasa);

    $this->actingAs($eu)
        ->putJson('/api/user/active-household', ['household_id' => $casaAlheia->id])
        ->assertInvalid(['household_id']);

    expect($eu->fresh()->active_household_id)->toBe($minhaCasa->id);
});

// -------------------------------------------------------- listar membros

it('membro comum enxerga quem mora na casa', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);

    $this->actingAs($membro)
        ->getJson("/api/households/{$casa->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('estranho não lista membros de casa alheia', function () {
    $casa = casaCom(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->getJson("/api/households/{$casa->id}/members")
        ->assertForbidden();
});

// ---------------------------------------------------------- mudar papel

it('admin promove membro a administrador', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}/members/{$membro->id}", ['papel' => 'admin'])
        ->assertOk()
        ->assertJsonPath('data.papel', 'admin');

    expect($membro->fresh()->isAdminOf($casa))->toBeTrue();
});

it('membro comum não muda papel de ninguém', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);

    $this->actingAs($membro)
        ->patchJson("/api/households/{$casa->id}/members/{$membro->id}", ['papel' => 'admin'])
        ->assertForbidden();

    expect($membro->fresh()->isAdminOf($casa))->toBeFalse();
});

it('impede rebaixar o último administrador', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}/members/{$admin->id}", ['papel' => 'member'])
        ->assertInvalid(['papel']);

    expect($admin->fresh()->isAdminOf($casa))->toBeTrue();
});

it('permite rebaixar admin quando existe outro', function () {
    $admin = User::factory()->create();
    $outroAdmin = User::factory()->create();
    $casa = casaCom($admin);
    $casa->members()->attach($outroAdmin->id, ['role' => HouseholdRole::Admin->value]);

    $this->actingAs($admin)
        ->patchJson("/api/households/{$casa->id}/members/{$admin->id}", ['papel' => 'member'])
        ->assertOk();

    expect($admin->fresh()->isAdminOf($casa))->toBeFalse();
});

// -------------------------------------------------------- remover membro

it('admin remove membro e limpa a casa ativa dele', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);
    definirCasaAtiva($membro, $casa);

    $this->actingAs($admin)
        ->deleteJson("/api/households/{$casa->id}/members/{$membro->id}")
        ->assertNoContent();

    $membro->refresh();

    expect($membro->isMemberOf($casa))->toBeFalse()
        ->and($membro->active_household_id)->toBeNull();
});

it('membro sai da casa por conta própria', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);

    $this->actingAs($membro)
        ->deleteJson("/api/households/{$casa->id}/members/{$membro->id}")
        ->assertNoContent();

    expect($membro->fresh()->isMemberOf($casa))->toBeFalse();
});

it('membro comum não remove outra pessoa', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaCom($admin, $membro);

    $this->actingAs($membro)
        ->deleteJson("/api/households/{$casa->id}/members/{$admin->id}")
        ->assertForbidden();

    expect($admin->fresh()->isMemberOf($casa))->toBeTrue();
});

it('impede remover o último administrador', function () {
    $admin = User::factory()->create();
    $casa = casaCom($admin, User::factory()->create());

    $this->actingAs($admin)
        ->deleteJson("/api/households/{$casa->id}/members/{$admin->id}")
        ->assertInvalid(['member']);

    expect($admin->fresh()->isMemberOf($casa))->toBeTrue();
});

it('quem sai cai em outra casa de que participa', function () {
    $eu = User::factory()->create();
    $casaA = casaCom(User::factory()->create());
    $casaA->members()->attach($eu->id, ['role' => HouseholdRole::Member->value]);
    $casaB = casaCom($eu);
    definirCasaAtiva($eu, $casaA);

    $this->actingAs($eu)
        ->deleteJson("/api/households/{$casaA->id}/members/{$eu->id}")
        ->assertNoContent();

    expect($eu->fresh()->active_household_id)->toBe($casaB->id);
});

// ------------------------------------------------------------------ IDOR

it('não alcança membro de outra casa pela URL da própria casa', function () {
    $eu = User::factory()->create();
    $minhaCasa = casaCom($eu);
    $vizinho = User::factory()->create();
    casaCom(User::factory()->create(), $vizinho);

    // scopeBindings: o vizinho não é membro da minha casa, então 404.
    $this->actingAs($eu)
        ->patchJson("/api/households/{$minhaCasa->id}/members/{$vizinho->id}", ['papel' => 'admin'])
        ->assertNotFound();

    $this->actingAs($eu)
        ->deleteJson("/api/households/{$minhaCasa->id}/members/{$vizinho->id}")
        ->assertNotFound();
});

it('exige autenticação em todas as rotas de casa', function () {
    $casa = casaCom(User::factory()->create());

    $this->getJson('/api/households')->assertUnauthorized();
    $this->getJson("/api/households/{$casa->id}/members")->assertUnauthorized();
    $this->putJson('/api/user/active-household', ['household_id' => $casa->id])->assertUnauthorized();
});
