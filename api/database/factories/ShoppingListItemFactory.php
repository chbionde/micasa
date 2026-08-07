<?php

namespace Database\Factories;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListItem>
 */
class ShoppingListItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'name' => 'Café',
        ];
    }

    public function checked(): static
    {
        return $this->state(fn () => ['checked_at' => now()]);
    }

    /** Item com todos os campos opcionais preenchidos. */
    public function completo(): static
    {
        return $this->state(fn () => [
            'quantity' => '2.000',
            'unit' => 'kg',
            'estimated_price_cents' => 4990,
            'priority' => 'alta',
            'store' => 'Mercado do bairro',
        ]);
    }
}
