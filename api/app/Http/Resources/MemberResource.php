<?php

namespace App\Http\Resources;

use App\Enums\HouseholdRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $papel = HouseholdRole::from($this->getAttribute('pivot')->getAttribute('role'));

        return [
            'id' => $this->id,
            'nome' => $this->name,
            'email' => $this->email,
            'papel' => $papel->value,
            'papel_label' => $papel->label(),
            'sou_eu' => $request->user()?->id === $this->id,
        ];
    }
}
