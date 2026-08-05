<?php

use App\Models\User;

it('registra um usuário novo e inicia a sessão', function () {
    $response = $this->post('/register', [
        'name' => 'Carlos Teste',
        'email' => 'carlos@exemplo.com.br',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
    ]);

    $response->assertNoContent();
    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', ['email' => 'carlos@exemplo.com.br']);
});

it('rejeita e-mail já cadastrado', function () {
    User::factory()->create(['email' => 'carlos@exemplo.com.br']);

    $response = $this->post('/register', [
        'name' => 'Outro Carlos',
        'email' => 'carlos@exemplo.com.br',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
    ]);

    $response->assertInvalid(['email']);
    $this->assertGuest();
});

it('rejeita senha sem confirmação correta', function () {
    $response = $this->post('/register', [
        'name' => 'Carlos Teste',
        'email' => 'carlos@exemplo.com.br',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'outra-coisa',
    ]);

    $response->assertInvalid(['password']);
    $this->assertGuest();
});

it('rejeita e-mail com letras maiúsculas', function () {
    $response = $this->post('/register', [
        'name' => 'Carlos Teste',
        'email' => 'Carlos@Exemplo.com.br',
        'password' => 'senha-forte-123',
        'password_confirmation' => 'senha-forte-123',
    ]);

    $response->assertInvalid(['email']);
    $this->assertGuest();
});
