<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

it('envia o link de redefinição para e-mail cadastrado', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('responde igual para e-mail inexistente', function () {
    Notification::fake();

    $cadastrado = $this->postJson('/forgot-password', [
        'email' => User::factory()->create()->email,
    ]);
    $inexistente = $this->postJson('/forgot-password', ['email' => 'ninguem@exemplo.com.br']);

    // Mesma resposta nos dois casos: a rota não pode virar um verificador
    // de quem tem conta no sistema.
    expect($inexistente->status())->toBe($cadastrado->status())
        ->and($inexistente->json('message'))->toBe($cadastrado->json('message'));

    Notification::assertCount(1);
});

it('aponta o link para a SPA, não para a API', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;

        return str_contains((string) $url, config('app.frontend_url').'/redefinir-senha/');
    });
});

it('redefine a senha com token válido', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'senha-nova-forte-1',
            'password_confirmation' => 'senha-nova-forte-1',
        ])->assertNoContent();

        return true;
    });

    expect(Hash::check('senha-nova-forte-1', $user->fresh()->password))->toBeTrue();
});

it('recusa token inválido', function () {
    $user = User::factory()->create();

    $this->postJson('/reset-password', [
        'token' => 'token-inventado',
        'email' => $user->email,
        'password' => 'senha-nova-forte-1',
        'password_confirmation' => 'senha-nova-forte-1',
    ])->assertInvalid(['email']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('recusa nova senha sem confirmação correta', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
        $this->postJson('/reset-password', [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'senha-nova-forte-1',
            'password_confirmation' => 'outra-coisa',
        ])->assertInvalid(['password']);

        return true;
    });
});
