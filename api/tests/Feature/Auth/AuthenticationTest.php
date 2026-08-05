<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

it('autentica com credenciais válidas', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertNoContent();
    $this->assertAuthenticatedAs($user);
});

it('rejeita senha errada com mensagem em pt-BR', function () {
    $user = User::factory()->create();

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'senha-errada',
    ]);

    $response->assertInvalid(['email' => __('auth.failed')]);
    $this->assertGuest();
    expect(__('auth.failed'))->not->toBe('auth.failed'); // tradução pt-BR carregada
});

it('bloqueia a sexta tentativa após cinco falhas', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $i) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'senha-errada',
        ]);
    }

    // Sexta tentativa: barrada pelo rate limit, mesmo com a senha CERTA.
    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertInvalid(['email']);
    $this->assertGuest();
});

it('não pune outro IP nem outro e-mail pelo bloqueio de um par', function () {
    $user = User::factory()->create();
    $outro = User::factory()->create();

    RateLimiter::clear(''); // higiene

    foreach (range(1, 5) as $i) {
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'senha-errada',
        ]);
    }

    // Outro e-mail no mesmo IP continua livre.
    $response = $this->post('/login', [
        'email' => $outro->email,
        'password' => 'password',
    ]);

    $response->assertNoContent();
});

it('encerra a sessão no logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/logout');

    $response->assertNoContent();
    $this->assertGuest();
});

it('nega logout sem sessão ativa', function () {
    $this->postJson('/logout')->assertUnauthorized();
});

it('retorna o usuário autenticado em /api/user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('email', $user->email);
});

it('nega /api/user sem autenticação', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});
