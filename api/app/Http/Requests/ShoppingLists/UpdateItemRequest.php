<?php

namespace App\Http\Requests\ShoppingLists;

use App\Enums\ItemPriority;
use App\Models\ShoppingList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $list = $this->route('shopping_list');

        return $list instanceof ShoppingList
            && $this->user()?->can('update', $list) === true;
    }

    /**
     * Tudo opcional: a tela edita um campo de cada vez (marcar comprado no
     * mercado, ajustar preço depois).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['sometimes', 'required', 'string', 'max:255'],
            'quantidade' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'unidade' => ['nullable', 'string', 'max:20'],
            'preco_centavos' => ['nullable', 'integer', 'min:0'],
            'prioridade' => ['nullable', Rule::enum(ItemPriority::class)],
            'loja' => ['nullable', 'string', 'max:255'],
            'comprado' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome do item',
            'preco_centavos' => 'preço estimado',
        ];
    }
}
