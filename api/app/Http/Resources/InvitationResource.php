<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invitation
 */
class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'papel' => $this->role->value,
            'papel_label' => $this->role->label(),
            'situacao' => $this->situacao(),
            'expira_em' => $this->expires_at->toIso8601String(),
            'criado_em' => $this->created_at?->toIso8601String(),
            'criado_por' => $this->whenLoaded('creator', fn () => $this->creator->name),
        ];
    }

    private function situacao(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'aceito',
            $this->revoked_at !== null => 'revogado',
            $this->expires_at->isPast() => 'expirado',
            default => 'pendente',
        };
    }
}
