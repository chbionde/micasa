<?php

use App\Actions\Households\CreateHouseholdForUser;
use App\Models\Household;
use App\Models\User;

function dadosDeRegistro(array $extra = []): array
{
    return array_merge([
        'name' => 'Carlos Bionde',
        'email' => 'carlos@exemplo.com.br',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
    ], $extra);
}

it('cria a casa, o vínculo de admin e a casa ativa ao registrar', function () {
    $this->post('/register', dadosDeRegistro())->assertNoContent();

    $user = User::firstWhere('email', 'carlos@exemplo.com.br');
    $casa = Household::sole();

    expect($user->active_household_id)->toBe($casa->id)
        ->and($user->isAdminOf($casa))->toBeTrue()
        ->and($casa->members)->toHaveCount(1);
});

it('usa o nome de casa informado', function () {
    $this->post('/register', dadosDeRegistro(['household_name' => 'Apê da Praia']))
        ->assertNoContent();

    expect(Household::sole()->name)->toBe('Apê da Praia');
});

it('nomeia a casa com o primeiro nome quando nada é informado', function () {
    $this->post('/register', dadosDeRegistro())->assertNoContent();

    expect(Household::sole()->name)->toBe('Casa de Carlos');
});

it('aplica o fuso padrão do Brasil na casa criada', function () {
    $this->post('/register', dadosDeRegistro())->assertNoContent();

    expect(Household::sole()->timezone)->toBe('America/Sao_Paulo');
});

it('rejeita nome de casa acima do limite', function () {
    $this->post('/register', dadosDeRegistro(['household_name' => str_repeat('a', 256)]))
        ->assertInvalid(['household_name']);

    expect(User::count())->toBe(0)
        ->and(Household::count())->toBe(0);
});

it('não deixa usuário órfão se a criação da casa falhar', function () {
    $this->mock(CreateHouseholdForUser::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('falha simulada'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->post('/register', dadosDeRegistro()))
        ->toThrow(RuntimeException::class);

    // A transação do controller precisa desfazer o usuário já inserido.
    expect(User::count())->toBe(0)
        ->and(Household::count())->toBe(0);
    $this->assertGuest();
});

it('dá papel de admin a cada pessoa na casa que ela mesma criou', function () {
    $this->post('/register', dadosDeRegistro())->assertNoContent();
    $this->post('/logout');

    $this->post('/register', dadosDeRegistro([
        'name' => 'Maria Silva',
        'email' => 'maria@exemplo.com.br',
    ]))->assertNoContent();

    $carlos = User::firstWhere('email', 'carlos@exemplo.com.br');
    $maria = User::firstWhere('email', 'maria@exemplo.com.br');
    $casaDoCarlos = Household::firstWhere('name', 'Casa de Carlos');
    $casaDaMaria = Household::firstWhere('name', 'Casa de Maria');

    expect($carlos->isAdminOf($casaDoCarlos))->toBeTrue()
        ->and($maria->isAdminOf($casaDaMaria))->toBeTrue()
        // Casas são independentes: ninguém entra na casa alheia por acidente.
        ->and($maria->isMemberOf($casaDoCarlos))->toBeFalse()
        ->and($carlos->isMemberOf($casaDaMaria))->toBeFalse();
});

it('cria casa para usuário existente via action, sem tocar em outras casas', function () {
    $user = User::factory()->create();
    $outraCasa = Household::factory()->create();

    $casa = app(CreateHouseholdForUser::class)->handle($user, 'Sítio');

    expect($casa->name)->toBe('Sítio')
        ->and($user->fresh()->active_household_id)->toBe($casa->id)
        ->and($user->isMemberOf($outraCasa))->toBeFalse();
});

it('lida com nome de uma palavra só', function () {
    $user = User::factory()->create(['name' => 'Madonna']);

    expect(app(CreateHouseholdForUser::class)->handle($user)->name)
        ->toBe('Casa de Madonna');
});
