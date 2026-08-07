<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;

function casaComMembros(?User $admin = null, ?User $membro = null): Household
{
    $casa = Household::factory()->create();
    $casa->members()->attach(($admin ?? User::factory()->create())->id, [
        'role' => HouseholdRole::Admin->value,
    ]);

    if ($membro !== null) {
        $casa->members()->attach($membro->id, ['role' => HouseholdRole::Member->value]);
    }

    return $casa;
}

// ------------------------------------------------------------- listagem

it('lista apenas as listas ativas por padrão', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);
    ShoppingList::factory()->create(['household_id' => $casa->id, 'name' => 'Mercado']);
    ShoppingList::factory()->archived()->create(['household_id' => $casa->id, 'name' => 'Natal 2025']);

    $this->actingAs($membro)
        ->getJson("/api/households/{$casa->id}/shopping-lists")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.nome', 'Mercado');
});

it('inclui arquivadas quando pedido, com as ativas primeiro', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);
    ShoppingList::factory()->archived()->create(['household_id' => $casa->id, 'name' => 'Natal 2025']);
    ShoppingList::factory()->create(['household_id' => $casa->id, 'name' => 'Mercado']);

    $this->actingAs($membro)
        ->getJson("/api/households/{$casa->id}/shopping-lists?arquivadas=1")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.nome', 'Mercado')
        ->assertJsonPath('data.0.arquivada', false)
        ->assertJsonPath('data.1.nome', 'Natal 2025')
        ->assertJsonPath('data.1.arquivada', true);
});

it('nunca mostra listas de outra casa', function () {
    $membro = User::factory()->create();
    $minhaCasa = casaComMembros(membro: $membro);
    $casaAlheia = casaComMembros();
    ShoppingList::factory()->create(['household_id' => $casaAlheia->id, 'name' => 'Lista alheia']);

    $this->actingAs($membro)
        ->getJson("/api/households/{$minhaCasa->id}/shopping-lists")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('estranho não lista as listas de uma casa', function () {
    $casa = casaComMembros();

    $this->actingAs(User::factory()->create())
        ->getJson("/api/households/{$casa->id}/shopping-lists")
        ->assertForbidden();
});

// -------------------------------------------------------------- criação

it('membro comum cria lista (ADR-019)', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);

    $this->actingAs($membro)
        ->postJson("/api/households/{$casa->id}/shopping-lists", ['nome' => 'Feira'])
        // 201: o Laravel detecta o recurso recém-criado e ajusta o status.
        ->assertCreated()
        ->assertJsonPath('data.nome', 'Feira')
        ->assertJsonPath('data.arquivada', false);

    expect(ShoppingList::sole()->created_by)->toBe($membro->id);
});

it('recusa lista sem nome', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);

    $this->actingAs($membro)
        ->postJson("/api/households/{$casa->id}/shopping-lists", ['nome' => '  '])
        ->assertInvalid(['nome']);
});

it('estranho não cria lista em casa alheia', function () {
    $casa = casaComMembros();

    $this->actingAs(User::factory()->create())
        ->postJson("/api/households/{$casa->id}/shopping-lists", ['nome' => 'Invasão'])
        ->assertForbidden();

    expect(ShoppingList::count())->toBe(0);
});

// -------------------------------------------------------------- edição

it('membro comum renomeia e arquiva a lista', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);
    $lista = ShoppingList::factory()->create(['household_id' => $casa->id]);

    $this->actingAs($membro)
        ->patchJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}", [
            'nome' => 'Mercado do mês',
            'arquivada' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.nome', 'Mercado do mês')
        ->assertJsonPath('data.arquivada', true);

    expect($lista->fresh()->archived_at)->not->toBeNull();
});

it('desarquiva a lista', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);
    $lista = ShoppingList::factory()->archived()->create(['household_id' => $casa->id]);

    $this->actingAs($membro)
        ->patchJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}", [
            'nome' => $lista->name,
            'arquivada' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.arquivada', false);
});

it('mantém o estado de arquivo quando o campo não é enviado', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);
    $lista = ShoppingList::factory()->archived()->create(['household_id' => $casa->id]);

    $this->actingAs($membro)
        ->patchJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}", ['nome' => 'Outro nome'])
        ->assertOk()
        ->assertJsonPath('data.arquivada', true);
});

// ------------------------------------------------------------ exclusão

it('admin apaga a lista, com soft delete', function () {
    $admin = User::factory()->create();
    $casa = casaComMembros($admin);
    $lista = ShoppingList::factory()->create(['household_id' => $casa->id]);

    $this->actingAs($admin)
        ->deleteJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}")
        ->assertNoContent();

    expect(ShoppingList::find($lista->id))->toBeNull()
        // Soft delete: o histórico continua no banco.
        ->and(ShoppingList::withTrashed()->find($lista->id))->not->toBeNull();
});

it('membro comum não apaga lista (ADR-019)', function () {
    $membro = User::factory()->create();
    $casa = casaComMembros(membro: $membro);
    $lista = ShoppingList::factory()->create(['household_id' => $casa->id]);

    $this->actingAs($membro)
        ->deleteJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}")
        ->assertForbidden();

    expect(ShoppingList::find($lista->id))->not->toBeNull();
});

// ------------------------------------------------------------------ IDOR

it('não alcança lista de outra casa pela URL da própria casa', function () {
    $membro = User::factory()->create();
    $minhaCasa = casaComMembros(membro: $membro);
    $casaAlheia = casaComMembros();
    $listaAlheia = ShoppingList::factory()->create(['household_id' => $casaAlheia->id]);

    // scopeBindings: a lista não pertence à casa da URL, então 404.
    $this->actingAs($membro)
        ->patchJson("/api/households/{$minhaCasa->id}/shopping-lists/{$listaAlheia->id}", [
            'nome' => 'Sequestrada',
        ])
        ->assertNotFound();

    $this->actingAs($membro)
        ->deleteJson("/api/households/{$minhaCasa->id}/shopping-lists/{$listaAlheia->id}")
        ->assertNotFound();

    expect($listaAlheia->fresh()->name)->not->toBe('Sequestrada');
});

it('exige autenticação nas rotas de lista', function () {
    $casa = casaComMembros();

    $this->getJson("/api/households/{$casa->id}/shopping-lists")->assertUnauthorized();
    $this->postJson("/api/households/{$casa->id}/shopping-lists", ['nome' => 'X'])->assertUnauthorized();
});
