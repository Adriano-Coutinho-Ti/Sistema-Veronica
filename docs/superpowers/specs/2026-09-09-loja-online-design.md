# Sub-projeto 3: Loja Online / App do Cliente — Sistema Veronica

Status: aprovado para virar plano de implementação
Data: 2026-09-09

## Contexto

O Sistema Veronica é um sistema de gestão para uma loja de brechó, decomposto
em 4 sub-projetos:

1. **Fundação** — implementado, revisado e em produção (login, clientes,
   produtos com categorias/variações configuráveis e estoque por combinação,
   fotos, aparência da loja, formas de entrega)
2. **PDV / Frente de Caixa** — implementado, revisado e em produção (caixa
   compartilhado, vendas presenciais, pagamento manual e Pix via Mercado
   Pago, fechamento com resumo por operador)
3. **Loja Online / App do Cliente** (este documento)
4. Linha de Crédito

Este documento cobre apenas a Loja Online: catálogo público, cadastro/login
de cliente, carrinho com reserva por prazo, checkout via Mercado Pago
cobrindo todos os meios de pagamento habilitados na conta da loja, e escolha
de forma de entrega. A Linha de Crédito como forma de pagamento e a área de
"meus débitos" do cliente **não** entram aqui — são o sub-projeto 4.

## Decisão-chave: reaproveitar as tabelas de venda do PDV

Em vez de criar `pedidos_online`/`itens_pedido_online` paralelas, este
sub-projeto **estende** as tabelas já existentes do PDV (`vendas`,
`itens_venda`, `venda_pagamentos`) e o núcleo `finalizarVenda()`
(`includes/caixa.php`, sub-projeto 2) — reaproveitados sem alteração de
lógica, só chamados por um canal diferente. Isso mantém vendas físicas e
online no mesmo lugar (importante pros relatórios financeiros futuros) e
evita duplicar a lógica de baixa de estoque/gravação de pagamento pela
terceira vez.

Migração necessária no banco (`ALTER TABLE`, aditiva, sem perda de dados —
`vendas` já tem linhas reais do PDV em produção):

```sql
ALTER TABLE vendas
    MODIFY COLUMN id_caixa INT NULL,
    MODIFY COLUMN id_usuario INT NULL,
    MODIFY COLUMN status ENUM('Reservado','Pago','Cancelado') NOT NULL DEFAULT 'Reservado',
    ADD COLUMN origem ENUM('pdv','loja') NOT NULL DEFAULT 'pdv';
```

Pedido da loja online: `id_caixa = NULL`, `id_usuario = NULL`,
`origem = 'loja'`, `id_cliente` sempre preenchido (login obrigatório pra
adicionar ao carrinho — ver abaixo). Linhas já existentes (todas do PDV)
recebem `origem = 'pdv'` pelo `DEFAULT`, o que já é o valor correto pra elas.

## Login do cliente

`clientes` (Fundação) ganha uma coluna nova:

```sql
ALTER TABLE clientes ADD COLUMN senha_hash VARCHAR(255) NULL;
```

`NULL` = cliente cadastrado pelo PDV/dona que ainda não ativou acesso
online. Fluxo de entrada:

1. Cliente informa o WhatsApp.
2. Se já existe um `clientes` com esse WhatsApp e `senha_hash IS NULL` →
   tela "Ativar minha conta" (só pede pra definir uma senha, reaproveitando
   o cadastro que já existe).
3. Se não existe nenhum registro com esse WhatsApp → cadastro completo
   (nome, WhatsApp, senha; e-mail/endereço continuam opcionais, mesma regra
   da Fundação).
4. Se existe e já tem `senha_hash` → tela de login normal (WhatsApp + senha).

Sessão do cliente usa uma chave própria (`$_SESSION['id_cliente']`),
totalmente separada da sessão de funcionário/admin
(`$_SESSION['id_usuario']`) — um `includes/auth_cliente.php` paralelo ao
`includes/auth.php` existente, com sua própria `exigirClienteLogado()`.

## Catálogo e carrinho

- Catálogo público: lista produtos `ativo = 1` com pelo menos uma
  combinação com estoque **disponível** (`estoque - estoque_reservado > 0`
  — ver reserva atômica abaixo), filtro por categoria, página de produto
  com seletor de variação (mesmo padrão de combinação do Fundação/PDV) e
  galeria das até 5 fotos.
- Adicionar ao carrinho exige login (`exigirClienteLogado()`).

**Regra fundamental (confirmada com o dono do produto): nunca duas pessoas
podem estar com o mesmo item — a mesma unidade de estoque — reservado ao
mesmo tempo, nem entre clientes online, nem entre um cliente online e uma
venda presencial no PDV.** Isso exige uma reserva atômica de verdade, não
só um cálculo "na hora" (que teria uma janela de corrida entre duas pessoas
lendo a disponibilidade ao mesmo tempo).

`produto_variacoes` (Fundação) ganha uma coluna nova:

```sql
ALTER TABLE produto_variacoes ADD COLUMN estoque_reservado INT NOT NULL DEFAULT 0;
```

**Adicionar ao carrinho** (loja online) faz uma reserva atômica antes de
inserir qualquer coisa — um `UPDATE` com guarda na própria cláusula
`WHERE`, que só afeta a linha se ainda houver disponibilidade suficiente:

```sql
UPDATE produto_variacoes
SET estoque_reservado = estoque_reservado + :qtd
WHERE id_produto_variacao = :id AND (estoque - estoque_reservado) >= :qtd
```

Se `0` linhas forem afetadas, não havia disponibilidade — a adição ao
carrinho falha imediatamente, sem inserir nada em `itens_venda` e sem criar
brecha de tempo entre "checar" e "reservar" (a trava é a própria condição
do `UPDATE`, resolvida atomicamente pelo MySQL). Só se a reserva for bem
sucedida o `itens_venda` é inserido.

**Remover do carrinho** (explícito, pelo cliente) devolve a reserva:
`estoque_reservado = estoque_reservado - :qtd` pra cada item removido,
antes de apagar a linha de `itens_venda`.

**Estoque real (`estoque`) só é debitado dentro de `finalizarVenda()`**, no
momento da confirmação de pagamento — mantém a trava `FOR UPDATE` já
existente do PDV. A única mudança necessária nessa função compartilhada é
o próprio `UPDATE` de baixa de estoque passar a também liberar a reserva
correspondente (a venda virou pagamento de verdade, então some tanto do
estoque quanto do "reservado"):

```sql
UPDATE produto_variacoes
SET estoque = estoque - :qtd,
    estoque_reservado = GREATEST(0, estoque_reservado - :qtd)
WHERE id_produto_variacao = :id
```

O `GREATEST(0, ...)` é o que torna essa mudança seguríssima pro PDV, que
**não** usa reserva nenhuma (vendas presenciais são feitas com o item na
mão, sem risco de concorrência remota) — pra uma venda do PDV,
`estoque_reservado` já é `0`, então o `GREATEST` simplesmente não faz nada,
sem quebrar o comportamento já em produção.

**Para valer a regra também no PDV** (item reservado online não pode ser
vendido presencialmente): `caixa/ajax/adicionar_item.php` (sub-projeto 2,
já em produção) precisa trocar sua checagem de `pv.estoque < :quantidade`
por `(pv.estoque - pv.estoque_reservado) < :quantidade` — um ajuste de uma
linha nesse arquivo já existente, incluído no plano deste sub-projeto.

## Expiração do carrinho (sem CRON)

`config_loja` (Fundação) ganha:

```sql
ALTER TABLE config_loja ADD COLUMN prazo_reserva_minutos INT NOT NULL DEFAULT 15;
```

Uma função `liberarReservasExpiradas($pdo)` (novo `includes/loja.php`) é
chamada no início de toda página/endpoint da loja que lê estoque ou
carrinho (catálogo, produto, carrinho, checkout). Pra cada venda "Reservado"
de `origem='loja'` mais velha que `prazo_reserva_minutos`, ela devolve a
reserva de cada item (`estoque_reservado = estoque_reservado - quantidade`,
mesmo cálculo do "remover do carrinho") e só então marca a venda como
`'Cancelado'`. O cliente vê uma contagem regressiva no carrinho, calculada
no navegador a partir de `data_venda + prazo_reserva_minutos` (sem depender
de nenhum relógio de servidor em tempo real).

## Checkout

1. Cliente logado, com carrinho não vazio, escolhe forma de entrega
   (`formas_entrega`, Fundação). Se não for "retirar na loja", pode editar
   o campo `clientes.endereco` (texto livre, já existente — sem endereços
   múltiplos/estruturados nesta fase).
2. Sistema gera uma **Preferência do Mercado Pago (Checkout Pro)** com os
   itens reais do carrinho, `marketplace_fee`/`sponsor_id` (mesmo padrão do
   PDV, 1% + `sponsor_id: 194420711`), e redireciona o cliente pro checkout
   hospedado do Mercado Pago — cobre todos os meios de pagamento habilitados
   na conta da loja (Pix, cartão, boleto), sem o sistema precisar processar
   dado de cartão.
3. Cliente paga no Mercado Pago, volta pro site numa página de
   sucesso/pendente/erro.
4. Confirmação de verdade acontece por **webhook** (endpoint próprio da
   loja, `loja/api/notificacao_mp.php` — mesma técnica de validação de
   assinatura X-Signature do PDV, mas endpoint separado porque a forma de
   achar a venda a partir do pagamento é diferente: aqui pela preferência
   Checkout Pro, não por um pagamento Pix direto). Ao confirmar aprovação,
   chama a mesma `finalizarVenda()` do PDV — debita estoque, grava
   pagamento, marca a venda como "Pago".

## Tratamento de erros

- **Item sem disponibilidade ao tentar adicionar ao carrinho** (o `UPDATE`
  atômico de reserva afetou `0` linhas) → mensagem clara, nada é
  adicionado. Com a reserva atômica, isso cobre tanto "esgotado de
  verdade" quanto "outra pessoa reservou primeiro", sem distinção
  necessária pro cliente.
- Carrinho vencido (expirado) ao tentar prosseguir pro checkout → aviso,
  itens somem do carrinho (reserva já devolvida por
  `liberarReservasExpiradas()`), cliente precisa adicionar de novo.
- **Rede de segurança final, na confirmação de pagamento**: mesmo com a
  reserva atômica cobrindo o caso normal, a checagem de estoque dentro de
  `finalizarVenda()` (trava `FOR UPDATE`, já existente do PDV) continua
  sendo a autoridade final antes de qualquer baixa de verdade — cobre
  qualquer cenário residual (ex: reserva expirou bem no meio do checkout,
  ou uma falha manual direta no banco). Se essa checagem falhar, o sistema
  **para e não finaliza a venda** — a página de status do pedido mostra
  exatamente: **"Item não liberado. Demora no pagamento."** O dinheiro já
  pode ter sido efetivamente cobrado pelo Mercado Pago nesse momento (o
  pagamento acontece no Mercado Pago, fora do nosso controle, antes do
  webhook chegar) — como não existe fluxo de estorno automático ainda, a
  mesma tela informa que a loja entrará em contato pra resolver
  (reembolso ou reposição). Com a reserva atômica em vigor, esse cenário
  fica extremamente raro (só aconteceria por um vencimento de prazo bem no
  limite ou uma falha fora do fluxo normal) — automatizar o estorno em si
  é escopo pra outro momento.
- Falha na geração da preferência do Mercado Pago (loja não conectada,
  erro de rede) → mensagem amigável, carrinho continua intacto (reserva
  não é afetada).
- Nonce/assinatura de webhook inválidos → mesmo tratamento do PDV (loga e
  responde 200, nunca deixa o Mercado Pago re-tentar indefinidamente).

## Testes

Sem suíte automatizada, mesmo padrão dos sub-projetos anteriores: validação
manual rodando o sistema de verdade (servidor PHP local + MySQL). O fluxo
completo de pagamento real do Mercado Pago (preferência, checkout hospedado,
webhook) só pode ser testado de ponta a ponta em produção, com a loja já
conectada ao Mercado Pago (pendência já conhecida do sub-projeto 2).
