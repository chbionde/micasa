<?php

namespace App\Models;

use App\Enums\ItemPriority;
use Database\Factories\ShoppingListItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shopping_list_id
 * @property string $name
 * @property string|null $quantity
 * @property string|null $unit
 * @property int|null $estimated_price_cents
 * @property ItemPriority|null $priority
 * @property string|null $store
 * @property Carbon|null $checked_at
 * @property int|null $checked_by
 * @property int|null $created_by
 * @property int $position
 */
#[Fillable(['name', 'quantity', 'unit', 'estimated_price_cents', 'priority', 'store', 'position'])]
class ShoppingListItem extends Model
{
    /** @use HasFactory<ShoppingListItemFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => ItemPriority::class,
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ShoppingList, $this>
     */
    public function shoppingList(): BelongsTo
    {
        return $this->belongsTo(ShoppingList::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function isChecked(): bool
    {
        return $this->checked_at !== null;
    }
}
