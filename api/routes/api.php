<?php

use App\Http\Controllers\InvitationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', fn (Request $request) => $request->user());

    // scopeBindings: o convite precisa pertencer à casa da URL, senão 404 —
    // proteção contra IDOR pela própria resolução de rota.
    Route::prefix('households/{household}')->scopeBindings()->group(function () {
        Route::get('/invitations', [InvitationController::class, 'index']);
        Route::post('/invitations', [InvitationController::class, 'store']);
        Route::delete('/invitations/{invitation}', [InvitationController::class, 'destroy']);
    });

    // Rate limit contra sondagem de tokens (o token tem 40 caracteres
    // aleatórios; força bruta já é inviável, isto é a segunda barreira).
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])
        ->middleware('throttle:10,1');
});
