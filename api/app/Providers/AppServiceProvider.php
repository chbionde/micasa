<?php

namespace App\Providers;

use App\Models\User;
use App\Rules\SenhaNaoVazada;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->politicaDeSenha();
        $this->limitesDeTentativa();

        // O link do e-mail precisa abrir a SPA, não a API — quem redefine a
        // senha é a tela do front, que depois chama POST /reset-password.
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => rtrim((string) config('app.frontend_url'), '/')
                .'/redefinir-senha/'.$token.'?email='.urlencode($user->email)
        );
    }

    /**
     * Política de senha do sistema inteiro — cadastro, troca e redefinição
     * chamam `Password::defaults()`, então este é o único lugar que decide.
     *
     * Comprimento em vez de "uma maiúscula, um número e um símbolo": a regra
     * de composição empurra a pessoa para `Senha@123`, que é longa o bastante
     * para o formulário e curta o bastante para o dicionário de ataque. O
     * NIST 800-63B desaconselha composição obrigatória desde 2017, e recomenda
     * exatamente o par usado aqui — mínimo generoso + confronto com vazamentos
     * conhecidos.
     */
    private function politicaDeSenha(): void
    {
        Password::defaults(function () {
            $regra = Password::min(10);

            // A regra própria entra no lugar do `uncompromised()` do Laravel,
            // que falha ABERTO — rede indisponível fazia a senha passar sem
            // verificação e sem aviso. Ver App\Rules\SenhaNaoVazada.
            //
            // Desligada durante a suíte para os testes não dependerem da
            // internet; o caminho de produção é exercitado ligando a chave e
            // falsificando o HTTP, inclusive no cenário de API fora do ar.
            return config('auth.checar_senha_vazada') === true
                ? $regra->rules([new SenhaNaoVazada])
                : $regra;
        });
    }

    private function limitesDeTentativa(): void
    {
        // Rotas de conta que conferem senha (`current_password`). Sem limite,
        // uma sessão roubada descobre a senha por força bruta e a usa para
        // apagar a conta — e o Laravel 11+ não aplica mais `throttle:api` ao
        // grupo `api` por padrão, então nada limitava por baixo.
        RateLimiter::for('conta-sensivel', fn (Request $request) => [
            Limit::perMinute(6)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        // Leitura e troca de contexto: teto alto, só para nada ficar sem teto.
        RateLimiter::for('conta-leitura', fn (Request $request) => Limit::perMinute(60)
            ->by('user:'.$request->user()?->getAuthIdentifier()));

        // O LoginRequest limita por e-mail+IP, o que protege UMA conta e não
        // protege contra o ataque inverso: uma senha comum contra muitas
        // contas (password spraying), em que cada par e-mail+IP é usado uma
        // vez só e nunca chega ao limite. Este limite é por IP apenas.
        //
        // 10 por minuto não incomoda uma casa inteira atrás do mesmo IP e
        // ainda assim tira o ataque do terreno produtivo — ainda mais somado
        // ao mínimo de 10 caracteres e ao confronto com vazamentos, que
        // esvaziam a lista de senhas que valeria a pena pulverizar. Serve
        // também de teto de CPU: cada tentativa é um bcrypt na VPS de 1 GB.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by('ip:'.$request->ip()));

        // Aceite de convite: limite por conta E por IP. Só por conta seria
        // contornável criando contas descartáveis, já que o cadastro é
        // público. O token tem 40 caracteres aleatórios — isto é a segunda
        // barreira, não a primeira.
        RateLimiter::for('aceite-convite', fn (Request $request) => [
            Limit::perMinute(10)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
    }
}
