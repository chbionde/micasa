<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

// Rotas de sessão da SPA (cookie + CSRF via /sanctum/csrf-cookie).
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('register');

// O LoginRequest continua limitando por e-mail+IP, com mensagem específica e
// contagem regressiva — é o que protege UMA conta de ser martelada, e é o que
// a pessoa precisa ler quando erra a própria senha.
//
// O limitador de rota abaixo é por IP apenas, e cobre o buraco que aquele
// deixa: no password spraying, cada par e-mail+IP é usado UMA vez, então a
// chave por e-mail nunca se aproxima do limite. Medido antes da correção: 20
// contas distintas, uma tentativa em cada, mesma origem — nenhum 429.
//
// As duas camadas não se confundem porque agem em ordens de grandeza
// diferentes: quem erra a própria senha esbarra no limite por e-mail muito
// antes de chegar a 10 requisições por minuto.
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('throttle:login')
    ->name('login');

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Redefinição de senha. Throttle apertado no envio: sem ele, a rota vira
// ferramenta de spam contra o e-mail de terceiros.
Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:5,1')
    ->name('password.email');

Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:5,1')
    ->name('password.store');
