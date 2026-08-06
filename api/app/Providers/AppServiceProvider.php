<?php

namespace App\Providers;

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
    }
}
