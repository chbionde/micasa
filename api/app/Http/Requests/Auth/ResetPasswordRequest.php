<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
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
            // O token vem do link do e-mail: 64 caracteres hexadecimais depois
            // do hash. O teto existe para o corpo da requisição não virar
            // entrada sem tamanho.
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            // Password::defaults() é a política única do sistema, definida no
            // AppServiceProvider — inclusive o confronto com vazamentos.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'e-mail', 'password' => 'nova senha'];
    }
}
