<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Aceite de convite: limite por conta E por IP. Só por conta seria
        // contornável criando contas descartáveis, já que o cadastro é
        // público. O token tem 40 caracteres aleatórios — isto é a segunda
        // barreira, não a primeira.
        RateLimiter::for('aceite-convite', fn (Request $request) => [
            Limit::perMinute(10)->by('user:'.$request->user()?->getAuthIdentifier()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);

        // O link do e-mail precisa abrir a SPA, não a API — quem redefine a
        // senha é a tela do front, que depois chama POST /reset-password.
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => rtrim((string) config('app.frontend_url'), '/')
                .'/redefinir-senha/'.$token.'?email='.urlencode($user->email)
        );
    }
}
