<?php

namespace App\Actions\Account;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Apaga as sessões gravadas de um usuário.
 *
 * Existe porque o Laravel não derruba sessão nenhuma sozinho. O middleware
 * `AuthenticateSession`, que amarraria cada sessão ao hash da senha, não está
 * registrado — e registrá-lo mudaria a semântica de sessão do app inteiro e
 * cobraria uma consulta por requisição. Como o driver de sessão aqui é o
 * banco (ADR-002), apagar as linhas resolve o mesmo problema com três linhas
 * e nenhum efeito colateral.
 *
 * Depende de `SESSION_DRIVER=database`: com driver de arquivo ou de cache não
 * há o que apagar aqui, e a troca de driver precisaria rever este ponto.
 */
class ForgetSessions
{
    /**
     * @param  string|null  $exceto  Id da sessão a preservar — normalmente a de
     *                               quem pediu a troca, que não deve ser
     *                               deslogado do próprio aparelho.
     * @return int Quantas sessões foram encerradas.
     */
    public function handle(User $user, ?string $exceto = null): int
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->when($exceto !== null, fn ($query) => $query->where('id', '!=', $exceto))
            ->delete();
    }
}
