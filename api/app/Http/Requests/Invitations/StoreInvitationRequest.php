<?php

namespace App\Http\Requests\Invitations;

use App\Enums\HouseholdRole;
use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvitationRequest extends FormRequest
{
    /**
     * Autorização antes da validação: sem isto, quem não pode convidar
     * receberia 422 (campo inválido) em vez de 403, revelando que o corpo
     * da requisição chegou a ser analisado.
     */
    public function authorize(): bool
    {
        $household = $this->route('household');

        return $household instanceof Household
            && $this->user()?->isAdminOf($household) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'papel' => ['nullable', Rule::enum(HouseholdRole::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['papel' => 'papel'];
    }
}
