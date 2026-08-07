<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;

function casaComLista(?User $membro = null): array
{
    $casa = Household::factory()->create();
    $casa->members()->attach(User::factory()->create()->id, ['role' => HouseholdRole::Admin->value]);

    if ($membro !== null) {
        $casa->members()->attach($membro->id, ['role' => HouseholdRole::Member->value]);
    }

    return [$casa, ShoppingList::factory()->create(['household_id' => $casa->id])];
}

function rotaItens(Household $casa, ShoppingList $lista): string
{
    return "/api/households/{$casa->id}/shopping-lists/{$lista->id}/items";
}

// -------------------------------------------------------------- criação

it('cria item só com o nome', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    $this->actingAs($membro)
        ->postJson(rotaItens($casa, $lista), ['nome' => 'Café'])
        ->assertCreated()
        ->assertJsonPath('data.nome', 'Café')
        ->assertJsonPath('data.quantidade', null)
        ->assertJsonPath('data.preco_centavos', null)
        ->assertJsonPath('data.comprado', false);
});

it('aceita todos os campos opcionais', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    $this->actingAs($membro)
        ->postJson(rotaItens($casa, $lista), [
            'nome' => 'Café',
            'quantidade' => 2.5,
            'unidade' => 'kg',
            'preco_centavos' => 4990,
            'prioridade' => 'alta',
            'loja' => 'Mercado do bairro',
        ])
        ->assertCreated()
        ->assertJsonPath('data.quantidade', 2.5)
        ->assertJsonPath('data.preco_centavos', 4990)
        ->assertJsonPath('data.prioridade_label', 'Alta');
});

it('guarda o preço como inteiro em centavos', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    $this->actingAs($membro)
        ->postJson(rotaItens($casa, $lista), ['nome' => 'Pão', 'preco_centavos' => 499]);

    $valor = ShoppingListItem::sole()->estimated_price_cents;

    expect($valor)->toBe(499)->toBeInt();
});

it('recusa preço fracionado (centavos são inteiros)', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    $this->actingAs($membro)
        ->postJson(rotaItens($casa, $lista), ['nome' => 'Pão', 'preco_centavos' => 4.99])
        ->assertInvalid(['preco_centavos']);
});

it('recusa prioridade inválida', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    $this->actingAs($membro)
        ->postJson(rotaItens($casa, $lista), ['nome' => 'Café', 'prioridade' => 'urgentissima'])
        ->assertInvalid(['prioridade']);
});

it('recusa item sem nome', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    $this->actingAs($membro)
        ->postJson(rotaItens($casa, $lista), ['nome' => ''])
        ->assertInvalid(['nome']);
});

it('empilha novos itens no fim da lista', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);

    foreach (['Café', 'Pão', 'Leite'] as $nome) {
        $this->actingAs($membro)->postJson(rotaItens($casa, $lista), ['nome' => $nome]);
    }

    $this->actingAs($membro)
        ->getJson(rotaItens($casa, $lista))
        ->assertOk()
        ->assertJsonPath('data.0.nome', 'Café')
        ->assertJsonPath('data.2.nome', 'Leite');
});

// -------------------------------------------------- marcar como comprado

it('marca item como comprado registrando quem marcou', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);
    $item = ShoppingListItem::factory()->create(['shopping_list_id' => $lista->id]);

    $this->actingAs($membro)
        ->patchJson(rotaItens($casa, $lista)."/{$item->id}", ['comprado' => true])
        ->assertOk()
        ->assertJsonPath('data.comprado', true)
        ->assertJsonPath('data.comprado_por', $membro->name);

    expect($item->fresh()->checked_by)->toBe($membro->id);
});

it('desmarca item limpando quem marcou', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);
    $item = ShoppingListItem::factory()->checked()->create([
        'shopping_list_id' => $lista->id,
        'checked_by' => $membro->id,
    ]);

    $this->actingAs($membro)
        ->patchJson(rotaItens($casa, $lista)."/{$item->id}", ['comprado' => false])
        ->assertOk()
        ->assertJsonPath('data.comprado', false);

    expect($item->fresh()->checked_by)->toBeNull();
});

it('marcar comprado não apaga os outros campos', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);
    $item = ShoppingListItem::factory()->completo()->create(['shopping_list_id' => $lista->id]);

    $this->actingAs($membro)
        ->patchJson(rotaItens($casa, $lista)."/{$item->id}", ['comprado' => true])
        ->assertOk()
        ->assertJsonPath('data.preco_centavos', 4990)
        ->assertJsonPath('data.unidade', 'kg');
});

// -------------------------------------------------------------- edição

it('edita um campo sem mexer nos outros', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);
    $item = ShoppingListItem::factory()->completo()->create(['shopping_list_id' => $lista->id]);

    $this->actingAs($membro)
        ->patchJson(rotaItens($casa, $lista)."/{$item->id}", ['preco_centavos' => 5990])
        ->assertOk()
        ->assertJsonPath('data.preco_centavos', 5990)
        ->assertJsonPath('data.unidade', 'kg')
        ->assertJsonPath('data.nome', 'Café');
});

it('limpa um campo opcional quando enviado como nulo', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);
    $item = ShoppingListItem::factory()->completo()->create(['shopping_list_id' => $lista->id]);

    $this->actingAs($membro)
        ->patchJson(rotaItens($casa, $lista)."/{$item->id}", ['loja' => null])
        ->assertOk()
        ->assertJsonPath('data.loja', null)
        ->assertJsonPath('data.unidade', 'kg');
});

it('remove item da lista', function () {
    $membro = User::factory()->create();
    [$casa, $lista] = casaComLista($membro);
    $item = ShoppingListItem::factory()->create(['shopping_list_id' => $lista->id]);

    $this->actingAs($membro)
        ->deleteJson(rotaItens($casa, $lista)."/{$item->id}")
        ->assertNoContent();

    expect(ShoppingListItem::count())->toBe(0);
});

it('apagar a lista leva os itens junto', function () {
    [$casa, $lista] = casaComLista();
    ShoppingListItem::factory()->count(3)->create(['shopping_list_id' => $lista->id]);

    $lista->forceDelete();

    expect(ShoppingListItem::count())->toBe(0);
});

// ------------------------------------------------------- autorização

it('estranho não vê nem cria itens de casa alheia', function () {
    [$casa, $lista] = casaComLista();
    $estranho = User::factory()->create();

    $this->actingAs($estranho)->getJson(rotaItens($casa, $lista))->assertForbidden();
    $this->actingAs($estranho)
        ->postJson(rotaItens($casa, $lista), ['nome' => 'Invasão'])
        ->assertForbidden();

    expect(ShoppingListItem::count())->toBe(0);
});

it('não alcança item de lista de outra casa pela URL da própria', function () {
    $membro = User::factory()->create();
    [$minhaCasa, $minhaLista] = casaComLista($membro);
    [, $listaAlheia] = casaComLista();
    $itemAlheio = ShoppingListItem::factory()->create(['shopping_list_id' => $listaAlheia->id]);

    // scopeBindings: o item não pertence à lista da URL, então 404.
    $this->actingAs($membro)
        ->patchJson(rotaItens($minhaCasa, $minhaLista)."/{$itemAlheio->id}", ['comprado' => true])
        ->assertNotFound();

    expect($itemAlheio->fresh()->checked_at)->toBeNull();
});

it('exige autenticação nas rotas de item', function () {
    [$casa, $lista] = casaComLista();

    $this->getJson(rotaItens($casa, $lista))->assertUnauthorized();
    $this->postJson(rotaItens($casa, $lista), ['nome' => 'X'])->assertUnauthorized();
});
