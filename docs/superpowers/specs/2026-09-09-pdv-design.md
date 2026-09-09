# Sub-projeto 2: PDV / Frente de Caixa — Sistema Veronica

Status: aprovado para virar plano de implementação
Data: 2026-09-09

## Contexto

O Sistema Veronica é um sistema de gestão para uma loja de brechó, decomposto
em 4 sub-projetos:

1. **Fundação** — implementado, revisado e em produção (login, clientes,
   produtos com categorias/variações configuráveis e estoque por combinação,
   fotos, aparência da loja, formas de entrega)
2. **PDV / Frente de Caixa** (este documento)
3. Loja Online / App do Cliente
4. Linha de Crédito

Este documento cobre apenas o PDV: venda presencial com pagamento em
dinheiro/cartão (lançamento manual) e Pix via Mercado Pago (QR Code real,
confirmação automática), e fechamento de caixa. A Linha de Crédito como
forma de pagamento no PDV **não** entra aqui — é plugada no sub-projeto 4.

## Referência de código

O projeto `D:\Projetos clientes - CODERNEX\sys01`, módulo `caixa/`, é o
modelo de código para este sub-projeto. Arquivos auditados e reaproveitados
como padrão:

- `caixa/includes/functions.php` — núcleo `finalizarVendaCaixaComPagamentos`:
  trava a venda e as linhas de estoque com `FOR UPDATE` dentro de uma
  transação, confere pagamento suficiente, confere estoque suficiente,
  debita estoque, grava pagamento(s), marca venda como paga. Reaproveitada
  tanto pelo botão "Finalizar" quanto pela confirmação automática via
  Mercado Pago (polling e webhook), para não duplicar a lógica.
- `caixa/ajax/abrir_caixa.php`, `caixa/ajax/fechar_caixa.php` — abertura com
  valor inicial, fechamento comparando dinheiro esperado (valor inicial +
  vendas em dinheiro) vs. valor contado, gravando a diferença.
- `caixa/ajax/gerar_pagamento_mp.php` — geração de cobrança Pix via
  `POST /v1/payments` (retorna QR Code base64 + código copia-e-cola direto,
  sem preferência/checkout), com `marketplace_fee` de 1% e `sponsor_id:
  194420711`.
- `integracoes/mercado_pago/conectar.php` + `callback.php` — fluxo OAuth
  "Conectar Mercado Pago": a aplicação CoderNex (Client ID/Secret, já
  existentes, reaproveitados de sys01) autoriza a conta MP de cada loja,
  com proteção CSRF via nonce de uso único gravado no banco.

## Decisão-chave: caixa compartilhado, não por operador

Diferente do sys01 (onde `caixa_aberturas` é por `id_usuario`), aqui **um
único caixa** fica aberto por vez e é usado por múltiplos operadores
simultaneamente (ex: a dona e uma funcionária, cada uma no próprio
celular). Cada venda grava qual operador a fez (`vendas.id_usuario`); o
fechamento mostra um resumo por operador. A checagem "existe caixa aberto?"
é global (existe alguma linha `status = 'aberto'`), não filtrada por
usuário.

Dois operadores podem tentar finalizar a venda do último item do mesmo
produto ao mesmo tempo — isso é resolvido pelo `FOR UPDATE` na linha de
estoque dentro da transação de finalização (o mesmo mecanismo do sys01),
sem necessidade de "reservar" estoque enquanto um item está no carrinho.

## Escopo

- Sessão de caixa compartilhada: abertura (valor inicial) e fechamento
  (esperado vs. contado, com resumo por operador). Sem sangria/suprimento
  por enquanto.
- Iniciar venda, buscar produto (por nome/categoria, sem leitor de código
  de barras), escolher a combinação (variação) do produto, adicionar ao
  carrinho, vincular cliente opcionalmente (busca por nome/WhatsApp, sem
  cadastro rápido nesta fase).
- Pagamento: dinheiro/débito/crédito como lançamento manual (o operador
  digita o valor recebido); Pix via Mercado Pago com QR Code real,
  confirmação automática por polling e por webhook. Pagamento misto (mais
  de uma forma na mesma venda) é permitido.
- Conexão OAuth com o Mercado Pago (a própria conta da loja).
- Fora de escopo: linha de crédito como forma de pagamento, loja online,
  orçamentos, vendas pausadas, estorno, sangria/suprimento, leitor de
  código de barras, desconto na venda.

## Modelo de dados

```sql
caixa_sessoes
  id_caixa PK
  valor_inicial DECIMAL(10,2)
  aberto_por INT FK -> usuarios(id_usuario)
  data_abertura DATETIME
  status ENUM('aberto','fechado')
  fechado_por INT NULL FK -> usuarios(id_usuario)
  data_fechamento DATETIME NULL
  valor_final_informado DECIMAL(10,2) NULL
  valor_esperado DECIMAL(10,2) NULL
  diferenca DECIMAL(10,2) NULL
  observacao_fechamento VARCHAR(255) NULL
  -- Só pode existir 1 linha com status='aberto' por vez (checado na
  -- aplicação antes do INSERT, não por constraint — mesmo padrão de
  -- "linha única" já usado em config_loja, mas aqui múltiplas linhas
  -- fechadas se acumulam como histórico).

vendas
  id_venda PK
  id_caixa INT FK -> caixa_sessoes(id_caixa)
  id_cliente INT NULL FK -> clientes(id_cliente)   -- NULL = venda avulsa
  id_usuario INT FK -> usuarios(id_usuario)         -- quem vendeu
  valor_total DECIMAL(10,2)
  status ENUM('Reservado','Pago')
  forma_pagamento VARCHAR(50) NULL                  -- 'Dinheiro'|'Débito'|'Crédito'|'Pix'|'Mista'
  id_pagamento_mp VARCHAR(50) NULL                  -- id do pagamento no MP, quando via Pix
  data_venda DATETIME
  -- SEM FK para produtos/produto_variacoes — itens_venda grava retrato
  -- (decisão da Fundação: produto pode ser deletado livremente).

itens_venda
  id_item PK
  id_venda INT FK -> vendas(id_venda) ON DELETE CASCADE
  nome_produto VARCHAR(150)          -- retrato, gravado na hora
  descricao_combinacao VARCHAR(255) NULL  -- ex: "Azul / M", retrato
  id_produto_variacao INT NULL       -- referência solta, sem FK (útil pra
                                      -- relatórios enquanto o produto ainda
                                      -- existe; pode ficar órfã depois)
  quantidade INT
  preco_unit DECIMAL(10,2)
  subtotal DECIMAL(10,2)

venda_pagamentos
  id_pagamento PK
  id_venda INT FK -> vendas(id_venda) ON DELETE CASCADE
  forma_pagamento VARCHAR(50)
  valor DECIMAL(10,2)
  data_pagamento DATETIME

config_pagamento
  id_config INT PRIMARY KEY           -- linha única, id_config=1
  mp_access_token VARCHAR(255) NULL
  mp_refresh_token VARCHAR(255) NULL
  mp_public_key VARCHAR(255) NULL
  mp_user_id VARCHAR(50) NULL
  mp_token_expira DATETIME NULL
  mp_oauth_nonce VARCHAR(64) NULL
  mp_oauth_nonce_expira DATETIME NULL
```

**Credenciais da aplicação CoderNex no Mercado Pago** (Client ID, Client
Secret, e o segredo de verificação do webhook) ficam em
`brechodaveve_config_credenciais.php`, fora da pasta pública — são dados da
CODERNEX, não da loja. O `sponsor_id` (194420711) é uma constante fixa no
código (não é segredo).

## Fluxos principais

**Abertura de caixa:**
1. Operador (Admin ou Funcionário) acessa `/caixa/` sem caixa aberto no
   sistema → tela de abertura, informa valor inicial em dinheiro.
2. Cria `caixa_sessoes` com `status = 'aberto'`.

**Venda:**
1. Operador logado com caixa aberto acessa `/caixa/` → inicia uma venda
   (`vendas` com `status = 'Reservado'`, `id_caixa` = sessão atual,
   `id_usuario` = quem está logado).
2. Busca produto por nome/categoria, escolhe a combinação de variação
   (estoque > 0), define quantidade, adiciona a `itens_venda` (retrato
   gravado na hora — nome do produto, descrição da combinação, preço
   vigente). Total da venda recalculado.
3. Opcionalmente vincula um cliente (busca por nome ou WhatsApp).
4. Pagamento:
   - **Dinheiro/Débito/Crédito**: operador digita o valor recebido pra
     cada forma (permite mistura, ex: parte dinheiro + parte débito).
   - **Pix**: operador clica "Gerar Pix" → chama a API do MP
     (`POST /v1/payments` com `marketplace_fee`/`sponsor_id`), mostra QR
     Code + código copia-e-cola. Tela faz polling a cada poucos segundos
     verificando o status do pagamento; o webhook (`caixa/api/
     notificacao_mp.php`) também pode confirmar, o que chegar primeiro.
5. Finalizar (manual) ou confirmação automática (Pix): dentro de uma
   transação, trava a linha da venda (`FOR UPDATE`, confere que ainda está
   "Reservado"), trava as linhas de `produto_variacoes` envolvidas
   (`FOR UPDATE`), confere estoque suficiente pra cada item, confere que o
   total pago cobre o valor da venda, debita o estoque, grava os
   pagamentos em `venda_pagamentos`, marca a venda como "Pago".

**Conexão com Mercado Pago (uma vez, ou quando o token expira):**
1. Dona/Admin acessa tela de configuração de pagamento, clica "Conectar
   Mercado Pago".
2. Redireciona para `auth.mercadopago.com` com um `state` contendo um
   nonce de uso único (gravado em `config_pagamento`, validade de 10 min).
3. `callback.php` valida o nonce contra o banco (proteção CSRF), troca o
   `code` por `access_token`/`refresh_token` na API do MP, grava em
   `config_pagamento`.

**Fechamento de caixa:**
1. Operador clica "Fechar caixa", informa o valor contado no fim do turno.
2. Sistema recalcula no servidor: esperado = valor inicial + soma de
   `venda_pagamentos` com `forma_pagamento = 'Dinheiro'` desde a abertura
   desta sessão. Diferença = contado - esperado.
3. Grava `valor_final_informado`, `valor_esperado`, `diferenca`,
   `fechado_por`, `data_fechamento`, muda `status` para `'fechado'`.
4. Tela de resumo mostra, por operador (`vendas.id_usuario` dentro desta
   sessão): número de vendas, valor total vendido, quebra por forma de
   pagamento.

## Tratamento de erros

- Tentar abrir um segundo caixa enquanto já existe um `status = 'aberto'`
  → bloqueado com aviso.
- Tentar vender sem caixa aberto → bloqueado, redireciona pra abertura.
- Estoque insuficiente no momento de finalizar (mesmo que parecesse
  disponível ao adicionar ao carrinho) → mensagem clara, venda não
  finaliza, item continua no carrinho pro operador ajustar a quantidade.
- Valor pago insuficiente → bloqueado até completar.
- Falha na API do Mercado Pago (rede, token expirado) → mensagem amigável;
  token expirado direciona pra reconectar.
- Webhook e polling podem chegar quase ao mesmo tempo — a trava `FOR
  UPDATE` na venda (que já checa `status = 'Reservado'` antes de agir)
  garante que só o primeiro a chegar finaliza; o segundo encontra a venda
  já "Pago" e não faz nada.
- Nonce OAuth expirado ou reaproveitado → erro claro, pede pra reconectar.

## Testes

Sem suíte automatizada, mesmo padrão da Fundação: validação manual rodando
o sistema de verdade (servidor PHP local + MySQL), incluindo teste real do
fluxo OAuth do Mercado Pago e de um Pix real (sandbox ou produção, a
definir na hora da implementação).
