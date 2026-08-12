<?php

/**
 * Correções da varredura de segurança (#43) — bloco de conta e sessão.
 *
 * Cada teste aqui falha na versão anterior do código. É o registro executável
 * dos achados A1 a A7 do docs/seguranca.md.
 *
 * Nota sobre `sessions`: o phpunit.xml roda com `SESSION_DRIVER=array`, então
 * o Laravel não grava sessão nenhuma durante a suíte. A tabela existe (a
 * migration roda), e é por isso que os testes de sessão inserem a linha à mão
 * — o que se está exercitando é a limpeza, não a gravação.
 */

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

function usuarioComCasa(string $senha = 'senha-atual-correta', ?string $email = null): User
{
    $user = User::factory()->create([
        ...$email !== null ? ['email' => $email] : [],
        'password' => Hash::make($senha),
    ]);

    Household::factory()->create()->members()->attach($user->id, [
        'role' => HouseholdRole::Admin->value,
    ]);

    return $user;
}

function gravarSessao(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '203.0.113.10',
        'user_agent' => 'aparelho-antigo',
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ]);
}

// ------------------------------------------------- A1: senha na troca de e-mail

it('recusa trocar o e-mail sem a senha atual', function () {
    $user = usuarioComCasa(email: 'vitima@exemplo.test');

    $this->actingAs($user)
        ->patchJson('/api/user/profile', ['name' => $user->name, 'email' => 'atacante@exemplo.test'])
        ->assertInvalid(['current_password']);

    expect($user->fresh()->email)->toBe('vitima@exemplo.test');
});

it('recusa trocar o e-mail com a senha atual errada', function () {
    $user = usuarioComCasa(email: 'vitima@exemplo.test');

    $this->actingAs($user)
        ->patchJson('/api/user/profile', [
            'name' => $user->name,
            'email' => 'atacante@exemplo.test',
            'current_password' => 'chute',
        ])
        ->assertInvalid(['current_password']);

    expect($user->fresh()->email)->toBe('vitima@exemplo.test');
});

it('troca o e-mail com a senha atual correta', function () {
    $user = usuarioComCasa(email: 'antigo@exemplo.test');

    $this->actingAs($user)
        ->patchJson('/api/user/profile', [
            'name' => $user->name,
            'email' => 'novo@exemplo.test',
            'current_password' => 'senha-atual-correta',
        ])
        ->assertOk();

    expect($user->fresh()->email)->toBe('novo@exemplo.test');
});

it('troca só o nome sem pedir senha', function () {
    $user = usuarioComCasa(email: 'mesmo@exemplo.test');

    $this->actingAs($user)
        ->patchJson('/api/user/profile', ['name' => 'Nome Novo', 'email' => 'mesmo@exemplo.test'])
        ->assertOk();

    expect($user->fresh()->name)->toBe('Nome Novo');
});

it('não pede senha quando o e-mail difere só na caixa', function () {
    $user = usuarioComCasa(email: 'ana@exemplo.test');

    // A regra `lowercase` já recusa o valor — mas a exigência de senha não
    // pode entrar de carona, porque o endereço não mudou de fato. Sem a
    // normalização em `trocaDeEmail()`, viriam dois erros e o segundo seria
    // mentira.
    $this->actingAs($user)
        ->patchJson('/api/user/profile', ['name' => $user->name, 'email' => 'ANA@exemplo.test'])
        ->assertInvalid(['email'])
        ->assertValid(['current_password']);
});

// ------------------------------------------ A1 + A2: a cadeia de tomada de conta

it('devolve a conta ao dono depois de sessão comprometida', function () {
    $vitima = usuarioComCasa(email: 'vitima@exemplo.test');
    gravarSessao($vitima, 'sessao-do-atacante');

    // 1. Quem tem a sessão tenta trocar o e-mail. Sem a senha, não passa.
    $this->actingAs($vitima)
        ->patchJson('/api/user/profile', ['name' => 'Vitima', 'email' => 'atacante@exemplo.test'])
        ->assertInvalid(['current_password']);

    // 2. A vítima desconfia e troca a senha.
    $this->actingAs($vitima->fresh())
        ->putJson('/api/user/password', [
            'current_password' => 'senha-atual-correta',
            'password' => 'nova-senha-bem-longa',
            'password_confirmation' => 'nova-senha-bem-longa',
        ])
        ->assertNoContent();

    // 3. A conta é dela, e o aparelho do atacante está fora.
    expect($vitima->fresh()->email)->toBe('vitima@exemplo.test')
        ->and(DB::table('sessions')->where('id', 'sessao-do-atacante')->exists())->toBeFalse();
});

// --------------------------------------- A2: troca de senha derruba as outras

it('trocar a senha encerra as sessões dos outros aparelhos', function () {
    $user = usuarioComCasa();
    gravarSessao($user, 'aparelho-um');
    gravarSessao($user, 'aparelho-dois');

    $this->actingAs($user)
        ->putJson('/api/user/password', [
            'current_password' => 'senha-atual-correta',
            'password' => 'nova-senha-bem-longa',
            'password_confirmation' => 'nova-senha-bem-longa',
        ])
        ->assertNoContent();

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);
});

it('trocar a senha não derruba a sessão de outra pessoa', function () {
    $user = usuarioComCasa();
    $outra = usuarioComCasa(email: 'outra@exemplo.test');
    gravarSessao($outra, 'sessao-de-terceiro');

    $this->actingAs($user)
        ->putJson('/api/user/password', [
            'current_password' => 'senha-atual-correta',
            'password' => 'nova-senha-bem-longa',
            'password_confirmation' => 'nova-senha-bem-longa',
        ])
        ->assertNoContent();

    expect(DB::table('sessions')->where('id', 'sessao-de-terceiro')->exists())->toBeTrue();
});

// ------------------------------------------------------- A3: política de senha

it('recusa senha curta no cadastro', function () {
    $this->postJson('/register', [
        'name' => 'Sonda',
        'email' => 'curta@exemplo.test',
        'password' => '12345678',
        'password_confirmation' => '12345678',
    ])->assertInvalid(['password']);
});

it('recusa senha curta na troca e na redefinição', function () {
    $user = usuarioComCasa();

    $this->actingAs($user)
        ->putJson('/api/user/password', [
            'current_password' => 'senha-atual-correta',
            'password' => '12345678',
            'password_confirmation' => '12345678',
        ])
        ->assertInvalid(['password']);
});

/*
 * O caminho de produção da política: `checar_senha_vazada` ligada. O HTTP é
 * falsificado no formato real da API do Have I Been Pwned — sufixos do SHA-1
 * a partir do sexto caractere, seguidos de `:` e da contagem de vazamentos.
 * Sem falsificar no formato certo, o teste passaria por acidente.
 */
it('recusa senha encontrada em vazamento quando a verificação está ligada', function () {
    config()->set('auth.checar_senha_vazada', true);

    $hash = strtoupper(sha1('senha-vazada-de-teste'));

    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).":42\n"),
    ]);

    $this->postJson('/register', [
        'name' => 'Sonda',
        'email' => 'vazada@exemplo.test',
        'password' => 'senha-vazada-de-teste',
        'password_confirmation' => 'senha-vazada-de-teste',
    ])->assertInvalid(['password']);
});

it('aceita senha longa e ausente dos vazamentos', function () {
    config()->set('auth.checar_senha_vazada', true);

    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response("0000000000000000000000000000000000:1\n"),
    ]);

    $this->postJson('/register', [
        'name' => 'Sonda',
        'email' => 'boa@exemplo.test',
        'password' => 'cafe-com-pao-na-cozinha',
        'password_confirmation' => 'cafe-com-pao-na-cozinha',
    ])->assertNoContent();
});

// ---------------------------------------------------- A4 e A6: rate limit

it('limita o chute da senha atual na troca de senha', function () {
    $user = usuarioComCasa();

    $ultimo = null;
    for ($i = 0; $i < 8; $i++) {
        $ultimo = $this->actingAs($user)->putJson('/api/user/password', [
            'current_password' => "chute-{$i}",
            'password' => 'nova-senha-bem-longa',
            'password_confirmation' => 'nova-senha-bem-longa',
        ]);
    }

    expect($ultimo->status())->toBe(429);
});

it('limita o chute da senha na exclusão de conta', function () {
    $user = usuarioComCasa();

    $ultimo = null;
    for ($i = 0; $i < 8; $i++) {
        $ultimo = $this->actingAs($user)->deleteJson('/api/user', ['password' => "chute-{$i}"]);
    }

    expect($ultimo->status())->toBe(429);
});

it('limita a sondagem de e-mails pelo perfil', function () {
    $sonda = usuarioComCasa();

    $ultimo = null;
    for ($i = 0; $i < 8; $i++) {
        $ultimo = $this->actingAs($sonda)->patchJson('/api/user/profile', [
            'name' => $sonda->name,
            'email' => "alvo{$i}@exemplo.test",
            'current_password' => 'senha-atual-correta',
        ]);
    }

    expect($ultimo->status())->toBe(429);
});

it('não limita a leitura do próprio usuário no uso normal', function () {
    $user = usuarioComCasa();

    // A SPA chama /api/user a cada carga de página; o teto existe, mas alto.
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($user)->getJson('/api/user')->assertOk();
    }
});

// ----------------------------------------------------- A5: spraying no login

it('limita tentativas de login por IP, e não só por e-mail', function () {
    $ultimo = null;

    for ($i = 0; $i < 15; $i++) {
        User::factory()->create(['email' => "alvo{$i}@exemplo.test"]);

        // Uma tentativa por conta: a chave e-mail+IP do LoginRequest nunca
        // chega perto do limite dela. Só um limite por IP barra isto.
        $ultimo = $this->postJson('/login', [
            'email' => "alvo{$i}@exemplo.test",
            'password' => 'Senha123',
        ]);
    }

    expect($ultimo->status())->toBe(429);
});

// ------------------------------------------- A7: exclusão não deixa rastro

it('apagar a conta apaga as sessões dela', function () {
    $user = usuarioComCasa();
    gravarSessao($user, 'sessao-orfa');

    $this->actingAs($user)
        ->deleteJson('/api/user', ['password' => 'senha-atual-correta'])
        ->assertNoContent();

    expect(DB::table('sessions')->where('id', 'sessao-orfa')->exists())->toBeFalse();
});

it('apagar a conta apaga o token de redefinição pendente', function () {
    $user = usuarioComCasa(email: 'sai@exemplo.test');

    $this->postJson('/forgot-password', ['email' => 'sai@exemplo.test'])->assertOk();
    expect(DB::table('password_reset_tokens')->where('email', 'sai@exemplo.test')->exists())->toBeTrue();

    $this->actingAs($user)
        ->deleteJson('/api/user', ['password' => 'senha-atual-correta'])
        ->assertNoContent();

    // Sem isto, o link antigo redefiniria a senha de quem viesse a cadastrar
    // este mesmo e-mail dentro da validade do token.
    expect(DB::table('password_reset_tokens')->where('email', 'sai@exemplo.test')->exists())->toBeFalse();
});

it('apagar a conta não apaga a sessão de outra pessoa', function () {
    $user = usuarioComCasa();
    $outra = usuarioComCasa(email: 'fica@exemplo.test');
    gravarSessao($outra, 'sessao-de-terceiro');

    $this->actingAs($user)
        ->deleteJson('/api/user', ['password' => 'senha-atual-correta'])
        ->assertNoContent();

    expect(DB::table('sessions')->where('id', 'sessao-de-terceiro')->exists())->toBeTrue();
});
