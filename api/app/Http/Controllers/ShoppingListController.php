<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShoppingLists\StoreShoppingListRequest;
use App\Http\Requests\ShoppingLists\UpdateShoppingListRequest;
use App\Http\Resources\ShoppingListResource;
use App\Models\Household;
use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ShoppingListController extends Controller
{
    public function index(Request $request, Household $household): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [ShoppingList::class, $household]);

        $listas = $household->shoppingLists()
            ->with('creator')
            // Ativas primeiro; dentro de cada grupo, a mais recente no topo.
            ->orderByRaw('archived_at is not null')
            ->latest()
            ->when(
                $request->boolean('arquivadas') === false,
                fn ($query) => $query->whereNull('archived_at')
            )
            ->get();

        return ShoppingListResource::collection($listas);
    }

    public function store(StoreShoppingListRequest $request, Household $household): ShoppingListResource
    {
        // created_by fica fora do #[Fillable]: é quem está autenticado, não
        // um campo que o cliente possa escolher.
        $lista = new ShoppingList(['name' => $request->string('nome')->value()]);
        $lista->created_by = $request->user()->id;

        $household->shoppingLists()->save($lista);

        return ShoppingListResource::make($lista->load('creator'));
    }

    public function update(
        UpdateShoppingListRequest $request,
        Household $household,
        ShoppingList $shoppingList,
    ): ShoppingListResource {
        $shoppingList->name = $request->string('nome')->value();

        if ($request->has('arquivada')) {
            $shoppingList->archived_at = $request->boolean('arquivada') ? now() : null;
        }

        $shoppingList->save();

        return ShoppingListResource::make($shoppingList->load('creator'));
    }

    public function destroy(Request $request, Household $household, ShoppingList $shoppingList): Response
    {
        $this->authorize('delete', $shoppingList);

        // Soft delete: histórico financeiro e de compras não se joga fora.
        $shoppingList->delete();

        return response()->noContent();
    }
}
