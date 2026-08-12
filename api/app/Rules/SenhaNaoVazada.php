<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Confronta a senha com a base de vazamentos públicos do Have I Been Pwned.
 *
 * Substitui o `uncompromised()` do Laravel por um motivo só: aquele FALHA
 * ABERTO. Em `NotPwnedVerifier::search()`, uma exceção de rede vira
 * `report($e)` e corpo vazio, e corpo vazio significa "nenhum vazamento
 * encontrado" — ou seja, a senha passa. A verificação some sem que ninguém
 * fique sabendo, e o log é o único vestígio.
 *
 * Aqui a rede indisponível recusa a senha, com mensagem própria dizendo que o
 * problema é a verificação e não a senha. O custo é honesto e está declarado:
 * com o Have I Been Pwned fora do ar, ninguém cadastra nem troca senha até a
 * API voltar.
 *
 * K-anonimato: sai apenas o prefixo de 5 caracteres do SHA-1, e a resposta
 * traz todos os sufixos daquele prefixo. A senha, o hash inteiro e o
 * resultado da comparação nunca saem daqui.
 */
class SenhaNaoVazada implements ValidationRule
{
    /** Medido em 12/08/2026: a API responde em 0,2–0,3 s. 5 s é folga de 20x. */
    private const TIMEOUT_SEGUNDOS = 5;

    private const TENTATIVAS = 2;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $hash = strtoupper(sha1($value));
        $prefixo = substr($hash, 0, 5);
        $sufixo = substr($hash, 5);

        try {
            $resposta = Http::withHeaders(['Add-Padding' => 'true'])
                ->timeout(self::TIMEOUT_SEGUNDOS)
                ->retry(self::TENTATIVAS, 200, throw: false)
                ->get('https://api.pwnedpasswords.com/range/'.$prefixo);
        } catch (Throwable) {
            $resposta = null;
        }

        if ($resposta === null || $resposta->failed()) {
            $fail('Não foi possível conferir sua senha contra vazamentos conhecidos agora. Tente de novo em instantes.');

            return;
        }

        if ($this->apareceEm($resposta->body(), $sufixo)) {
            $fail('Esta senha aparece em vazamentos públicos de dados e já está em listas de ataque. Escolha outra.');
        }
    }

    /**
     * Cada linha é `SUFIXO:OCORRENCIAS`. O cabeçalho `Add-Padding` faz a API
     * devolver entradas falsas para o tamanho da resposta não denunciar
     * quantos vazamentos aquele prefixo tem — e as falsas vêm com contagem
     * zero, por isso a contagem precisa ser conferida.
     */
    private function apareceEm(string $corpo, string $sufixo): bool
    {
        foreach (explode("\n", trim($corpo)) as $linha) {
            if (! str_contains($linha, ':')) {
                continue;
            }

            [$candidato, $ocorrencias] = explode(':', trim($linha), 2);

            if (hash_equals($sufixo, $candidato) && (int) $ocorrencias > 0) {
                return true;
            }
        }

        return false;
    }
}
