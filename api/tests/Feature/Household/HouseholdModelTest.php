<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\QueryException;

it('relaciona casa e membros com papel no pivô', function () {
    $casa = Household::factory()->create();
    $admin = User::factory()->create();
    $membro = User::factory()->create();

    $casa->members()->attach($admin->id, ['role' => HouseholdRole::Admin->value]);
    $casa->members()->attach($membro->id, ['role' => HouseholdRole::Member->value]);

    expect($casa->members)->toHaveCount(2)
        ->and($casa->admins()->pluck('users.id')->all())->toBe([$admin->id])
        ->and($admin->isAdminOf($casa))->toBeTrue()
        ->and($membro->isAdminOf($casa))->toBeFalse()
        ->and($membro->isMemberOf($casa))->toBeTrue();
});

it('devolve null como papel de quem não é membro', function () {
    $casa = Household::factory()->create();
    $estranho = User::factory()->create();

    expect($casa->roleOf($estranho))->toBeNull()
        ->and($estranho->isMemberOf($casa))->toBeFalse();
});

it('permite que uma pessoa participe de várias casas com papéis diferentes', function () {
    $pessoa = User::factory()->create();
    $minhaCasa = Household::factory()->create();
    $casaDosPais = Household::factory()->create();

    $minhaCasa->members()->attach($pessoa->id, ['role' => HouseholdRole::Admin->value]);
    $casaDosPais->members()->attach($pessoa->id, ['role' => HouseholdRole::Member->value]);

    expect($pessoa->households)->toHaveCount(2)
        ->and($pessoa->roleIn($minhaCasa))->toBe(HouseholdRole::Admin)
        ->and($pessoa->roleIn($casaDosPais))->toBe(HouseholdRole::Member);
});

it('impede vínculo duplicado da mesma pessoa na mesma casa', function () {
    $casa = Household::factory()->create();
    $pessoa = User::factory()->create();

    $casa->members()->attach($pessoa->id, ['role' => HouseholdRole::Member->value]);

    expect(fn () => $casa->members()->attach($pessoa->id, ['role' => HouseholdRole::Admin->value]))
        ->toThrow(QueryException::class);
});

it('usa America/Sao_Paulo como fuso padrão da casa', function () {
    expect(Household::factory()->create()->timezone)->toBe('America/Sao_Paulo');
});

it('aponta a casa ativa do usuário', function () {
    $casa = Household::factory()->create();
    $pessoa = User::factory()->create(['active_household_id' => $casa->id]);

    expect($pessoa->activeHousehold->is($casa))->toBeTrue();
});

it('libera a casa ativa quando a casa é apagada', function () {
    $casa = Household::factory()->create();
    $pessoa = User::factory()->create(['active_household_id' => $casa->id]);

    $casa->delete();

    expect($pessoa->fresh()->active_household_id)->toBeNull();
});

it('considera utilizável apenas o convite vigente', function () {
    expect(Invitation::factory()->create()->isUsable())->toBeTrue()
        ->and(Invitation::factory()->expired()->create()->isUsable())->toBeFalse()
        ->and(Invitation::factory()->revoked()->create()->isUsable())->toBeFalse()
        ->and(Invitation::factory()->accepted()->create()->isUsable())->toBeFalse();
});

it('esconde o hash do token ao serializar o convite', function () {
    $convite = Invitation::factory()->create();

    expect($convite->toArray())->not->toHaveKey('token_hash');
});

it('converte o papel do convite para enum', function () {
    $convite = Invitation::factory()->create(['role' => HouseholdRole::Admin]);

    expect($convite->fresh()->role)->toBe(HouseholdRole::Admin);
});
