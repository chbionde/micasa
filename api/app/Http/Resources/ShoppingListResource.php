<?php

namespace App\Http\Resources;

use App\Models\ShoppingList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShoppingList
 */
class ShoppingListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->name,
            'arquivada' => $this->isArchived(),
            'arquivada_em' => $this->archived_at?->toIso8601String(),
            'criada_em' => $this->created_at?->toIso8601String(),
            'criada_por' => $this->whenLoaded('creator', fn () => $this->creator?->name),
        ];
    }
}
