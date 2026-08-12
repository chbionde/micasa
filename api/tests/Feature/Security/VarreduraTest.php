<?php

/**
 * Testes nascidos da varredura de segurança da issue #43.
 *
 * Cada um prova um item do checklist que a varredura declarou SEGURO. Sem
 * eles, o veredito era uma leitura de código com data de validade: o
 * comportamento continuaria certo até alguém mexer, e nada avisaria.
 *
 * O que NÃO está aqui: os achados. Cada falha encontrada tem issue própria,
 * e o teste que falha antes da correção nasce junto com a correção.
 */

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;

function casaVarredura(?User $admin = null, ?User $membro = null): Household
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

// ---------------------------------------------------------------------- IDOR

/*
 * Os testes de IDOR que já existiam cruzam a fronteira da CASA. Este cruza a
 * fronteira da LISTA dentro da mesma casa — o caso que o scopeBindings do
 * grupo aninhado resolve, e que ninguém tinha exercitado. Se um dia o
 * `->group()` interno de routes/api.php sair de dentro do grupo com
 * scopeBindings, é este teste que fica vermelho.
 */
it('não alcança item de outra lista da mesma casa pela URL errada', function () {
    $membro = User::factory()->create();
    $casa = casaVarredura(membro: $membro);
    $listaA = ShoppingList::factory()->create(['household_id' => $casa->id]);
    $listaB = ShoppingList::factory()->create(['household_id' => $casa->id]);
    $itemDeB = ShoppingListItem::factory()->create([
        'shopping_list_id' => $listaB->id,
        'name' => 'Café',
    ]);

    $this->actingAs($membro)
        ->patchJson("/api/households/{$casa->id}/shopping-lists/{$listaA->id}/items/{$itemDeB->id}", [
            'nome' => 'sequestrado',
        ])
        ->assertNotFound();

    expect($itemDeB->fresh()->name)->toBe('Café');
});

// ----------------------------------------------------------- mass assignment

/*
 * O padrão do projeto é #[Fillable] enxuto no model e atribuição explícita no
 * controller. Estes quatro testes provam o efeito prático: campos de
 * propriedade e de autoria não são escolhidos por quem envia a requisição.
 */

it('não deixa o corpo da requisição escolher a casa dona da lista', function () {
    $membro = User::factory()->create();
    $casa = casaVarredura(membro: $membro);
    $alheia = casaVarredura();

    $this->actingAs($membro)
        ->postJson("/api/households/{$casa->id}/shopping-lists", [
            'nome' => 'Mercado',
            'household_id' => $alheia->id,
            'created_by' => 99999,
        ])
        ->assertCreated();

    $lista = ShoppingList::firstWhere('name', 'Mercado');

    expect($lista->household_id)->toBe($casa->id)
        ->and($lista->created_by)->toBe($membro->id);
});

it('não deixa o corpo da requisição forjar quem marcou o item', function () {
    $membro = User::factory()->create();
    $casa = casaVarredura(membro: $membro);
    $lista = ShoppingList::factory()->create(['household_id' => $casa->id]);

    $this->actingAs($membro)
        ->postJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}/items", [
            'nome' => 'Café',
            'checked_by' => 99999,
            'checked_at' => now()->toIso8601String(),
            'position' => 500,
            'shopping_list_id' => 12345,
        ])
        ->assertCreated();

    $item = ShoppingListItem::firstWhere('name', 'Café');

    expect($item->checked_by)->toBeNull()
        ->and($item->checked_at)->toBeNull()
        ->and($item->shopping_list_id)->toBe($lista->id)
        // A posição é calculada pelo servidor (fim da lista), não escolhida.
        ->and($item->position)->toBe(1);
});

it('não deixa o corpo do aceite escolher o papel na casa', function () {
    $admin = User::factory()->create();
    $casa = casaVarredura($admin);
    $convidado = User::factory()->create();

    $token = $this->actingAs($admin)
        ->postJson("/api/households/{$casa->id}/invitations", [])
        ->json('token');

    // O papel vale o que o convite gravou, não o que o convidado pede.
    $this->actingAs($convidado)
        ->postJson("/api/invitations/{$token}/accept", ['papel' => 'admin', 'role' => 'admin'])
        ->assertOk();

    expect($casa->fresh()->roleOf($convidado))->toBe(HouseholdRole::Member);
});

it('não deixa o cadastro entrar numa casa alheia pelo corpo', function () {
    $alheia = casaVarredura();

    $this->postJson('/register', [
        'name' => 'Intruso',
        'email' => 'intruso@exemplo.test',
        'password' => 'senha-longa-o-suficiente',
        'password_confirmation' => 'senha-longa-o-suficiente',
        'active_household_id' => $alheia->id,
        'household_id' => $alheia->id,
    ])->assertNoContent();

    $novo = User::firstWhere('email', 'intruso@exemplo.test');

    expect($novo->isMemberOf($alheia))->toBeFalse()
        ->and($novo->active_household_id)->not->toBe($alheia->id);
});

// ------------------------------------------------------------ tamanho de payload

/*
 * Texto livre sem teto é negação de serviço barata: o SQLite engole, e a
 * tela de quem for ler a lista trava. O `max:255` do FormRequest é a barreira.
 */
it('recusa nome de item absurdamente grande', function () {
    $membro = User::factory()->create();
    $casa = casaVarredura(membro: $membro);
    $lista = ShoppingList::factory()->create(['household_id' => $casa->id]);

    $this->actingAs($membro)
        ->postJson("/api/households/{$casa->id}/shopping-lists/{$lista->id}/items", [
            'nome' => str_repeat('a', 100_000),
        ])
        ->assertInvalid(['nome']);
});

// ------------------------------------------------------------------ exposição

/*
 * O #[Hidden] do model é fácil de perder num refactor. Em vez de confiar nele,
 * varremos o corpo cru das respostas atrás do hash — inclusive do prefixo
 * "$2y$" do bcrypt, que apareceria mesmo se a chave fosse renomeada.
 */
it('nenhuma resposta autenticada carrega hash de senha ou remember_token', function () {
    $membro = User::factory()->create();
    $casa = casaVarredura(membro: $membro);

    $rotas = [
        '/api/user',
        '/api/households',
        "/api/households/{$casa->id}/members",
    ];

    foreach ($rotas as $rota) {
        $corpo = $this->actingAs($membro)->getJson($rota)->content();

        expect($corpo)->not->toContain('password')
            ->and($corpo)->not->toContain('remember_token')
            ->and($corpo)->not->toContain('$2y$');
    }
});

// ------------------------------------------------------------- enumeração

/*
 * Achado A13 da #43. A mensagem de "casa que não existe" era diferente da de
 * "casa que existe mas não é sua", e a diferença dizia a um usuário
 * autenticado quais ids de casa existem.
 *
 * O teste compara as duas respostas inteiras, não só o texto: status, chaves
 * e mensagens. Qualquer canal que volte a separar os dois casos fica vermelho.
 */
it('responde igual para casa inexistente e casa alheia', function () {
    $forasteiro = User::factory()->create();
    $alheia = casaVarredura();

    $existente = $this->actingAs($forasteiro)
        ->putJson('/api/user/active-household', ['household_id' => $alheia->id]);

    $inexistente = $this->actingAs($forasteiro)
        ->putJson('/api/user/active-household', ['household_id' => 999_999]);

    expect($inexistente->status())->toBe($existente->status())
        ->and($inexistente->json())->toBe($existente->json());
});

it('a mensagem de casa indisponível não afirma que a casa existe', function () {
    $forasteiro = User::factory()->create();

    $resposta = $this->actingAs($forasteiro)
        ->putJson('/api/user/active-household', ['household_id' => 999_999]);

    // Fechar a enumeração dizendo "você não faz parte desta casa" sobre uma
    // casa apagada seria trocar mensagem verdadeira por falsa.
    expect($resposta->json('errors.household_id.0'))
        ->not->toContain('não faz parte')
        ->and($resposta->json('errors.household_id.0'))->toContain('Atualize a página');
});
