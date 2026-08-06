<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    // scopeBindings: o convite precisa pertencer à casa da URL, senão 404 —
    // proteção contra IDOR pela própria resolução de rota.
    Route::prefix('households/{household}')
        ->scopeBindings()
        ->middleware('throttle:30,1')
        ->group(function () {
            Route::get('/invitations', [InvitationController::class, 'index']);
            Route::post('/invitations', [InvitationController::class, 'store']);
            Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
        });

    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:aceite-convite');
});
