<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SwitchActiveHousehold
{
    /**
     * Mensagem única para "casa que não existe" e "casa que não é sua".
     *
     * Antes eram duas mensagens diferentes, e a diferença permitia a um
     * usuário autenticado descobrir quais ids de casa existem (achado A13 da
     * varredura #43).
     *
     * A correção óbvia — usar "Você não faz parte desta casa" nos dois casos —
     * fecharia a enumeração mentindo: quem tem um id de casa velho, de uma
     * casa já apagada, leria que não faz parte de algo que não existe, e sairia
     * atrás de quem administra o nada. Esta mensagem é verdadeira nos dois
     * casos, idêntica nos dois casos, e diz o que fazer.
     */
    public const INDISPONIVEL = 'Não foi possível ativar esta casa. Atualize a página para ver suas casas atuais.';

    /**
     * @throws ValidationException
     */
    public function handle(User $user, Household $household): void
    {
        // Trocar de contexto não é atalho para entrar em casa alheia.
        if (! $user->isMemberOf($household)) {
            throw ValidationException::withMessages([
                'household_id' => self::INDISPONIVEL,
            ]);
        }

        $user->active_household_id = $household->id;
        $user->save();
    }
}
