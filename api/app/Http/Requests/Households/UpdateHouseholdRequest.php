<?php

namespace App\Http\Requests\Households;

use App\Models\Household;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHouseholdRequest extends FormRequest
{
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
            'nome' => ['required', 'string', 'max:255'],
            // Fuso precisa ser um identificador real da base IANA, senão o
            // cálculo de lembretes quebraria silenciosamente (ADR-008).
            'fuso' => ['sometimes', 'string', Rule::in(timezone_identifiers_list())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nome' => 'nome da casa', 'fuso' => 'fuso horário'];
    }
}
