<?php

namespace App\Http\Controllers\Account;

use App\Actions\Account\DeleteAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Requests\Account\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AccountController extends Controller
{
    public function updateProfile(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();

        $user->update($request->only('name', 'email'));

        return UserResource::make($user->fresh()->load('activeHousehold'));
    }

    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $request->user();

        $user->password = Hash::make($request->string('password')->value());
        $user->save();

        // Sessão nova depois de trocar a senha: se alguém tinha a sessão
        // antiga em outro aparelho, o token dela deixa de valer. Rotas de
        // API só têm sessão quando vêm de domínio stateful (Sanctum SPA).
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->noContent();
    }

    public function destroy(DeleteAccountRequest $request, DeleteAccount $deleteAccount): Response
    {
        $user = $request->user();

        // Encerrar a sessão ANTES de apagar: o logout recicla o remember
        // token e salva o usuário, e um save() em modelo já apagado vira
        // INSERT — a conta ressuscitaria.
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $deleteAccount->handle($user);

        return response()->noContent();
    }
}
