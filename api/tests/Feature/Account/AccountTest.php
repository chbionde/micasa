<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

function casaDe(User $admin, ?User $outro = null): Household
{
    $casa = Household::factory()->create();
    $casa->members()->attach($admin->id, ['role' => HouseholdRole::Admin->value]);

    if ($outro !== null) {
        $casa->members()->attach($outro->id, ['role' => HouseholdRole::Member->value]);
    }

    return $casa;
}

// ------------------------------------------------------------ perfil

it('atualiza nome e e-mail', function () {
    $user = User::factory()->create(['password' => Hash::make('senha-da-pessoa')]);

    // A senha atual entrou como exigência na varredura #43: o e-mail é a
    // chave de recuperação da conta. Ver tests/Feature/Security.
    $this->actingAs($user)
        ->patchJson('/api/user/profile', [
            'name' => 'Carlos B.',
            'email' => 'novo@exemplo.com.br',
            'current_password' => 'senha-da-pessoa',
        ])
        ->assertOk()
        ->assertJsonPath('data.email', 'novo@exemplo.com.br');

    expect($user->fresh()->name)->toBe('Carlos B.');
});

it('permite salvar mantendo o próprio e-mail', function () {
    $user = User::factory()->create(['email' => 'eu@exemplo.com.br']);

    $this->actingAs($user)
        ->patchJson('/api/user/profile', ['name' => 'Outro Nome', 'email' => 'eu@exemplo.com.br'])
        ->assertOk();
});

it('recusa e-mail já usado por outra pessoa', function () {
    User::factory()->create(['email' => 'ocupado@exemplo.com.br']);
    $user = User::factory()->create(['password' => Hash::make('senha-da-pessoa')]);

    // Com a senha correta, o único motivo de recusa é a unicidade.
    $this->actingAs($user)
        ->patchJson('/api/user/profile', [
            'name' => 'Carlos',
            'email' => 'ocupado@exemplo.com.br',
            'current_password' => 'senha-da-pessoa',
        ])
        ->assertInvalid(['email']);
});

// ------------------------------------------------------------- senha

it('troca a senha informando a atual', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/user/password', [
            'current_password' => 'password',
            'password' => 'nova-senha-forte-1',
            'password_confirmation' => 'nova-senha-forte-1',
        ])
        ->assertNoContent();

    expect(Hash::check('nova-senha-forte-1', $user->fresh()->password))->toBeTrue();
});

it('recusa troca de senha com senha atual errada', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/user/password', [
            'current_password' => 'chute',
            'password' => 'nova-senha-forte-1',
            'password_confirmation' => 'nova-senha-forte-1',
        ])
        ->assertInvalid(['current_password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

// ---------------------------------------------------- exclusão de conta

it('apaga a conta e as casas em que morava sozinho', function () {
    $user = User::factory()->create();
    $casaSozinho = casaDe($user);

    $this->actingAs($user)
        ->deleteJson('/api/user', ['password' => 'password'])
        ->assertNoContent();

    expect(User::find($user->id))->toBeNull()
        ->and(Household::find($casaSozinho->id))->toBeNull();
});

it('não ressuscita a conta ao encerrar a sessão', function () {
    // Auth::logout() recicla o remember token e salva o usuário; feito
    // depois do delete, esse save vira INSERT e recria a conta apagada.
    $user = User::factory()->create(['remember_token' => 'token-antigo']);

    $this->actingAs($user)->deleteJson('/api/user', ['password' => 'password'])->assertNoContent();

    expect(User::withoutGlobalScopes()->find($user->id))->toBeNull()
        ->and(User::count())->toBe(0);
});

it('preserva casa com outras pessoas ao apagar a conta de um membro', function () {
    $admin = User::factory()->create();
    $membro = User::factory()->create();
    $casa = casaDe($admin, $membro);

    $this->actingAs($membro)->deleteJson('/api/user', ['password' => 'password'])->assertNoContent();

    expect(Household::find($casa->id))->not->toBeNull()
        ->and($casa->members()->count())->toBe(1);
});

it('bloqueia exclusão de quem é o único admin de casa com outras pessoas', function () {
    $admin = User::factory()->create();
    $casa = casaDe($admin, User::factory()->create());
    $casa->update(['name' => 'Casa da Família']);

    $this->actingAs($admin)
        ->deleteJson('/api/user', ['password' => 'password'])
        ->assertInvalid(['password']);

    expect(User::find($admin->id))->not->toBeNull()
        ->and(Household::find($casa->id))->not->toBeNull();
});

it('recusa exclusão sem a senha correta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/user', ['password' => 'chute'])
        ->assertInvalid(['password']);

    expect(User::find($user->id))->not->toBeNull();
});

it('exige autenticação nas rotas de conta', function () {
    $this->patchJson('/api/user/profile', ['name' => 'X', 'email' => 'x@y.com'])->assertUnauthorized();
    $this->putJson('/api/user/password', [])->assertUnauthorized();
    $this->deleteJson('/api/user', [])->assertUnauthorized();
});
