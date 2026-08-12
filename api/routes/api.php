<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\ShoppingListItemController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // A partir do Laravel 11 o grupo `api` não traz mais `throttle:api`, então
    // rota sem limite declarado é rota SEM limite nenhum. Aqui não sobra
    // nenhuma: leitura leva teto folgado, e o que confere senha leva teto
    // apertado (limitadores em AppServiceProvider).
    Route::middleware('throttle:conta-leitura')->group(function () {
        Route::get('/user', fn (Request $request) => UserResource::make(
            $request->user()->load('activeHousehold')
        ));

        Route::get('/households', [HouseholdController::class, 'index']);
        Route::put('/user/active-household', [HouseholdController::class, 'switchActive']);
    });

    // As três conferem senha: as duas últimas por `current_password`, e a
    // primeira desde que passou a exigi-la para trocar o e-mail. Sem limite,
    // são um oráculo de força bruta — e `updateProfile` era, além disso, um
    // verificador ilimitado de quais e-mails têm conta, pela regra `unique`.
    Route::middleware('throttle:conta-sensivel')->group(function () {
        Route::patch('/user/profile', [AccountController::class, 'updateProfile']);
        Route::put('/user/password', [AccountController::class, 'updatePassword']);
        Route::delete('/user', [AccountController::class, 'destroy']);
    });

    // scopeBindings: convite e membro precisam pertencer à casa da URL,
    // senão 404 — proteção contra IDOR pela própria resolução de rota.
    Route::prefix('households/{household}')
        ->scopeBindings()
        ->middleware('throttle:30,1')
        ->group(function () {
            Route::patch('/', [HouseholdController::class, 'update']);

            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);

            Route::get('/shopping-lists', [ShoppingListController::class, 'index']);
            Route::post('/shopping-lists', [ShoppingListController::class, 'store']);
            Route::patch('/shopping-lists/{shopping_list}', [ShoppingListController::class, 'update']);
            Route::delete('/shopping-lists/{shopping_list}', [ShoppingListController::class, 'destroy']);

            Route::prefix('shopping-lists/{shopping_list}')->group(function () {
                Route::get('/items', [ShoppingListItemController::class, 'index']);
                Route::post('/items', [ShoppingListItemController::class, 'store']);
                Route::patch('/items/{item}', [ShoppingListItemController::class, 'update']);
                Route::delete('/items/{item}', [ShoppingListItemController::class, 'destroy']);
            });

            Route::get('/members', [MemberController::class, 'index']);
            Route::patch('/members/{member}', [MemberController::class, 'update']);
            Route::delete('/members/{member}', [MemberController::class, 'destroy']);
        });

    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:aceite-convite');
});
