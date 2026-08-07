<?php

namespace App\Http\Resources;

use App\Models\ShoppingListItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ShoppingListItem
 */
class ShoppingListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->name,
            // Quantidade sai como número para o front não lidar com string
            // decimal do banco; null continua null.
            'quantidade' => $this->quantity !== null ? (float) $this->quantity : null,
            'unidade' => $this->unit,
            // Centavos crus: formatar em R$ é responsabilidade da borda.
            'preco_centavos' => $this->estimated_price_cents,
            'prioridade' => $this->priority?->value,
            'prioridade_label' => $this->priority?->label(),
            'loja' => $this->store,
            'comprado' => $this->isChecked(),
            'comprado_em' => $this->checked_at?->toIso8601String(),
            'comprado_por' => $this->whenLoaded('checkedBy', fn () => $this->checkedBy?->name),
            'posicao' => $this->position,
        ];
    }
}
