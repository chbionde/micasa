<?php

namespace App\Http\Requests\ShoppingLists;

use App\Models\Household;
use App\Models\ShoppingList;
use Illuminate\Foundation\Http\FormRequest;

class StoreShoppingListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $household = $this->route('household');

        return $household instanceof Household
            && $this->user()?->can('create', [ShoppingList::class, $household]) === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return ['nome' => ['required', 'string', 'max:255']];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nome' => 'nome da lista'];
    }
}
