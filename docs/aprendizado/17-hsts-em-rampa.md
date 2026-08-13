# HSTS em rampa: fechar a primeira conexão sem criar um bloqueio longo

## O problema que o redirecionamento não resolve

O MiCasa já redireciona HTTP para HTTPS. Mesmo assim, quando alguém digita apenas o domínio, a
primeira requisição pode sair em HTTP e só então receber o redirecionamento. Numa rede hostil,
essa primeira conexão ainda pode ser interceptada.

`Strict-Transport-Security` (HSTS) é uma instrução recebida por HTTPS e guardada pelo navegador.
Nas visitas seguintes, ele troca HTTP por HTTPS antes de acessar a rede.

## Por que não começar com um ano

HSTS também remove a opção de ignorar um certificado inválido. Se a renovação falhar, quem já
recebeu a política não consegue abrir o site até o TLS voltar ou o prazo terminar.

Por isso o MiCasa usa uma rampa:

1. cinco minutos (`max-age=300`), para um erro ter impacto curto;
2. depois de alguns dias estáveis, 30 dias (`max-age=2592000`);
3. um ano somente após observar uma renovação automática real.

Cada etapa é uma mudança versionada e aplicada pelo runbook do nginx. A produção nunca deve ter
um valor diferente do repositório sem isso estar registrado.

## Por que não há `includeSubDomains` nem `preload`

`includeSubDomains` estende a obrigação de HTTPS a nomes abaixo de
`micasa-bionde.duckdns.org`. Hoje eles não existem, então não há benefício prático nem motivo
para comprometer futuros subdomínios antes de saber como serão usados.

`preload` tenta inserir o domínio numa lista distribuída dentro dos navegadores. A remoção pode
demorar meses, portanto é um compromisso desproporcional para um domínio gratuito e para um
projeto que ainda está amadurecendo sua operação.

## Como verificar

Depois do merge, a configuração ainda não está ativa: o deploy normal não altera o nginx. Na
VPS, aplique pelo runbook e, de qualquer máquina, confira:

```bash
curl -sSI https://micasa-bionde.duckdns.org/ \
  | grep -i '^strict-transport-security:'
```

Na primeira etapa deve existir exatamente uma linha:

```text
Strict-Transport-Security: max-age=300
```

Também vale medir HTML, um asset e uma resposta da API. O `aplicar-nginx.sh` faz essas três
leituras e reprova valor duplicado, divergente ou acompanhado de `includeSubDomains`/`preload`.

## Como desfazer a etapa inicial

Se o TLS falhar, restaure o certificado primeiro. Remover o cabeçalho impede novos navegadores
de aprender a política, mas quem já recebeu `max-age=300` ainda a conserva por até cinco
minutos. Essa espera é o risco residual deliberadamente limitado da primeira etapa.

## Quão comum é no mercado

HSTS é uma defesa comum em aplicações HTTPS. A ativação gradual é especialmente comum quando a
equipe ainda está provando renovação de certificado e procedimentos de recuperação. `preload`
é reservado para domínios cuja operação aceita um compromisso de longa duração.
