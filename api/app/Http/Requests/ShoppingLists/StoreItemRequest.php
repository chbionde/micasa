<?php

namespace App\Http\Requests\ShoppingLists;

use App\Enums\ItemPriority;
use App\Models\ShoppingList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $list = $this->route('shopping_list');

        return $list instanceof ShoppingList
            && $this->user()?->can('update', $list) === true;
    }

    /**
     * Só o nome é obrigatório — preencher o resto não pode ser massante
     * (ADR-010).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'quantidade' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'unidade' => ['nullable', 'string', 'max:20'],
            // Centavos: inteiro, nunca float (ADR-015).
            'preco_centavos' => ['nullable', 'integer', 'min:0'],
            'prioridade' => ['nullable', Rule::enum(ItemPriority::class)],
            'loja' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome do item',
            'quantidade' => 'quantidade',
            'unidade' => 'unidade',
            'preco_centavos' => 'preço estimado',
            'prioridade' => 'prioridade',
            'loja' => 'loja',
        ];
    }
}
