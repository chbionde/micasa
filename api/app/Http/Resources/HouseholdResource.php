<?php

namespace App\Http\Resources;

use App\Models\Household;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Household
 */
class HouseholdResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $usuario = $request->user();

        return [
            'id' => $this->id,
            'nome' => $this->name,
            'fuso' => $this->timezone,
            // Papel de quem está pedindo — o front usa para decidir o que
            // mostrar, e o back continua validando por Policy.
            'meu_papel' => $usuario !== null ? $this->roleOf($usuario)?->value : null,
        ];
    }
}
