<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShoppingLists\StoreItemRequest;
use App\Http\Requests\ShoppingLists\UpdateItemRequest;
use App\Http\Resources\ShoppingListItemResource;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ShoppingListItemController extends Controller
{
    public function index(
        Request $request,
        Household $household,
        ShoppingList $shoppingList,
    ): AnonymousResourceCollection {
        $this->authorize('view', $shoppingList);

        return ShoppingListItemResource::collection(
            $shoppingList->items()->with('checkedBy')->get()
        );
    }

    public function store(
        StoreItemRequest $request,
        Household $household,
        ShoppingList $shoppingList,
    ): ShoppingListItemResource {
        $item = new ShoppingListItem($this->camposDoItem($request));
        $item->created_by = $request->user()->id;
        // Novo item vai para o fim da lista.
        $item->position = (int) $shoppingList->items()->max('position') + 1;

        $shoppingList->items()->save($item);

        return ShoppingListItemResource::make($item);
    }

    public function update(
        UpdateItemRequest $request,
        Household $household,
        ShoppingList $shoppingList,
        ShoppingListItem $item,
    ): ShoppingListItemResource {
        $item->fill($this->camposDoItem($request, parcial: true));

        if ($request->has('comprado')) {
            $comprado = $request->boolean('comprado');

            // Guardamos quem marcou: "quem comprou o café?" é pergunta real
            // numa casa.
            $item->checked_at = $comprado ? now() : null;
            $item->checked_by = $comprado ? $request->user()->id : null;
        }

        $item->save();

        return ShoppingListItemResource::make($item->load('checkedBy'));
    }

    public function destroy(
        Request $request,
        Household $household,
        ShoppingList $shoppingList,
        ShoppingListItem $item,
    ): Response {
        $this->authorize('update', $shoppingList);

        $item->delete();

        return response()->noContent();
    }

    /**
     * Traduz os nomes em pt-BR da API para as colunas do banco.
     *
     * @return array<string, mixed>
     */
    private function camposDoItem(Request $request, bool $parcial = false): array
    {
        $mapa = [
            'nome' => 'name',
            'quantidade' => 'quantity',
            'unidade' => 'unit',
            'preco_centavos' => 'estimated_price_cents',
            'prioridade' => 'priority',
            'loja' => 'store',
        ];

        $campos = [];

        foreach ($mapa as $entrada => $coluna) {
            // Na edição parcial, campo ausente fica como está; campo enviado
            // como null limpa o valor.
            if (! $parcial || $request->has($entrada)) {
                $campos[$coluna] = $request->input($entrada);
            }
        }

        return $campos;
    }
}
