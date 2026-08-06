<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Households\CreateHouseholdForUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class RegisteredUserController extends Controller
{
    public function store(RegisterRequest $request, CreateHouseholdForUser $createHousehold): Response
    {
        // Usuário e casa nascem juntos ou não nascem: conta sem casa seria
        // um estado que toda query precisaria tratar (ADR-007).
        $user = DB::transaction(function () use ($request, $createHousehold) {
            $user = User::create([
                'name' => $request->string('name')->value(),
                'email' => $request->string('email')->value(),
                'password' => Hash::make($request->string('password')->value()),
            ]);

            $createHousehold->handle($user, $request->string('household_name')->value() ?: null);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return response()->noContent();
    }
}
