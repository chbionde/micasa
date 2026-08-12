<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'lowercase', 'email', 'max:255',
                // Ignora o próprio registro: salvar sem mudar o e-mail não
                // pode acusar "e-mail já cadastrado".
                Rule::unique('users')->ignore($this->user()?->id),
            ],
            // O e-mail é a chave de recuperação da conta: quem o troca passa a
            // receber o link de "esqueci minha senha". Sem esta exigência, uma
            // sessão emprestada por cinco minutos vira posse permanente — o
            // dono troca a senha depois, e a conta continua apontando para o
            // e-mail de quem entrou. Trocar o nome segue livre.
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->trocaDeEmail()),
                'current_password',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['current_password' => 'senha atual'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Informe sua senha atual para trocar o e-mail.',
        ];
    }

    /**
     * O `lowercase` da regra de e-mail compara o valor cru; aqui o
     * enquadramento precisa ser o mesmo, senão trocar `Ana@x.com` por
     * `ana@x.com` pediria senha sem que o endereço tivesse mudado de fato.
     */
    private function trocaDeEmail(): bool
    {
        $atual = $this->user()?->email;

        if ($atual === null) {
            return false;
        }

        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower($atual);
    }
}
