<?php

namespace App\Http\Requests\Households;

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
}
