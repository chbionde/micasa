<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\HouseholdController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => UserResource::make(
        $request->user()->load('activeHousehold')
    ));

    Route::get('/households', [HouseholdController::class, 'index']);
    Route::put('/user/active-household', [HouseholdController::class, 'switchActive']);

    Route::patch('/user/profile', [AccountController::class, 'updateProfile']);
    Route::put('/user/password', [AccountController::class, 'updatePassword']);
    Route::delete('/user', [AccountController::class, 'destroy']);

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

            Route::get('/members', [MemberController::class, 'index']);
            Route::patch('/members/{member}', [MemberController::class, 'update']);
            Route::delete('/members/{member}', [MemberController::class, 'destroy']);
        });

    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:aceite-convite');
});
