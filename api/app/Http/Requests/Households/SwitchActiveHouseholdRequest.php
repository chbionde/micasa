<?php

namespace App\Http\Requests\Households;

use App\Actions\Households\SwitchActiveHousehold;
use Illuminate\Foundation\Http\FormRequest;

class SwitchActiveHouseholdRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'household_id' => ['required', 'integer', 'exists:households,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['household_id' => 'casa'];
    }

    /**
     * A mensagem do `exists` precisa ser IDÊNTICA à que a action devolve para
     * casa alheia — é a diferença entre as duas que denunciava quais ids
     * existem. A constante mora na action para não haver duas cópias do texto
     * envelhecendo em separado.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['household_id.exists' => SwitchActiveHousehold::INDISPONIVEL];
    }
}
