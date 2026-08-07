<?php

namespace App\Http\Requests\ShoppingLists;

use App\Models\ShoppingList;
use Illuminate\Foundation\Http\FormRequest;

class UpdateShoppingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $list = $this->route('shopping_list');

        return $list instanceof ShoppingList
            && $this->user()?->can('update', $list) === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'arquivada' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nome' => 'nome da lista'];
    }
}
