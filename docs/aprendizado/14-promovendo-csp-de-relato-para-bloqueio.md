# Promovendo a CSP de relato para bloqueio

## O problema

Uma Content Security Policy (CSP) diz ao navegador de onde scripts, estilos, imagens, fontes e
requisições podem vir. Uma política errada pode impedir o próprio site de carregar, então a #53
não publicou a configuração estrita diretamente em modo bloqueante.

## A implantação em duas fases

Primeiro, a política completa foi enviada em `Content-Security-Policy-Report-Only`. Nesse modo o
navegador registra no console o que seria bloqueado, mas não interrompe a página. Em paralelo,
uma política menor já bloqueava plugins, `<base>`, enquadramento e formulários externos.

Depois foram verificadas:

1. respostas de HTML, assets e API com `curl`;
2. ausência de scripts, estilos e fontes externos no build;
3. navegação autenticada em janela anônima, com o console aberto;
4. ausência de mensagens de violação da política em modo relato.

Com essas evidências, a configuração versionada passou a política estrita para
`Content-Security-Policy` e removeu o `Content-Security-Policy-Report-Only`.

Isso ainda não altera a VPS: o deploy normal não instala configurações do nginx. Depois do
merge, `infra/aplicar-nginx.sh` precisa ser executado na produção. O script só termina com
sucesso quando encontra a política bloqueante em HTML, asset e API e confirma a ausência do
cabeçalho de relato.

## Como reproduzir a conferência

Depois de aplicar o nginx, confira os cabeçalhos de três tipos de resposta:

```bash
curl -sSI https://micasa-bionde.duckdns.org/
asset=$(curl -sS https://micasa-bionde.duckdns.org/ | grep -oE '/assets/[^" ]+\.js' | head -n 1)
curl -sSI "https://micasa-bionde.duckdns.org${asset}"
curl -sSI https://micasa-bionde.duckdns.org/api/user
```

Nos três casos deve existir um único `Content-Security-Policy`, contendo `default-src 'self'`, e
não deve existir `Content-Security-Policy-Report-Only`. A API pode responder `401` sem sessão;
isso não é falha da CSP, pois o objetivo aqui é medir os cabeçalhos.

A conferência final acontece em uma janela anônima, com o console do navegador aberto. Navegue
pelas telas reais e procure mensagens contendo `Content Security Policy`. Um erro HTTP comum,
como `401 Unauthorized`, só pertence à CSP se a mensagem disser que uma diretiva bloqueou o
recurso.

## Como mudar a política no futuro

Se uma funcionalidade precisar de uma origem externa, conteúdo inline, Web Worker ou outra
capacidade hoje bloqueada, não acrescente permissões por tentativa. Copie a política candidata
para `Content-Security-Policy-Report-Only`, mantenha a política bloqueante atual e teste apenas o
fluxo novo. Depois libere a menor origem ou capacidade que a evidência exigir.

Evite permissões amplas como `*`, `'unsafe-inline'` e `'unsafe-eval'`: elas removem grande parte
do benefício que motivou a CSP.

## Quão comum é essa prática

Implantar CSP primeiro em modo relato e promovê-la depois de observar tráfego e navegação é uma
prática comum em aplicações web. Em sistemas maiores, os relatórios costumam ir para um endpoint
de coleta. Para o MiCasa, com poucos usuários e escopo doméstico, a validação manual das telas e
do build entrega sinal suficiente sem introduzir serviço, armazenamento ou monitoramento novo.
