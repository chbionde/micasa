<?php

namespace App\Actions\Account;

use App\Actions\Households\UpdateMemberRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteAccount
{
    public function __construct(private readonly ForgetSessions $forgetSessions) {}

    /**
     * Apaga a conta. Casas em que a pessoa era a única moradora vão junto;
     * casas com outras pessoas exigem que ela promova alguém antes, para
     * não deixar ninguém trancado numa casa sem administrador.
     *
     * @throws ValidationException
     */
    public function handle(User $user): void
    {
        $casas = $user->households()->get();

        $presas = $casas->filter(
            fn (Household $casa) => $casa->members()->count() > 1
                && $user->isAdminOf($casa)
                && UpdateMemberRole::ehUltimoAdmin($casa, $user)
        );

        if ($presas->isNotEmpty()) {
            throw ValidationException::withMessages([
                'password' => 'Você é o único administrador de: '.$presas->pluck('name')->join(', ')
                    .'. Promova outra pessoa a administrador antes de apagar sua conta.',
            ]);
        }

        DB::transaction(function () use ($user, $casas) {
            foreach ($casas as $casa) {
                if ($casa->members()->count() === 1) {
                    $casa->delete();
                }
            }

            // Duas sobras que a exclusão deixava para trás. Nenhuma das duas
            // tem chave estrangeira que o banco possa cascatear: `sessions`
            // nasce sem constraint na migration padrão do Laravel, e
            // `password_reset_tokens` é indexada por e-mail, não por id.
            //
            // A sessão órfã não autentica ninguém — na requisição seguinte o
            // guard procura o usuário, não acha, e a requisição sai anônima.
            // O que ela é: endereço de IP e user agent guardados depois de um
            // pedido explícito de exclusão de conta.
            $this->forgetSessions->handle($user);

            // Este é o de consequência concreta: enquanto o token vale, o
            // link antigo redefine a senha de QUALQUER conta que venha a
            // existir com este e-mail.
            DB::table(config('auth.passwords.users.table', 'password_reset_tokens'))
                ->where('email', $user->email)
                ->delete();

            $user->delete();
        });
    }
}
