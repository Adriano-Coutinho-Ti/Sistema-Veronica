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
  combinação com estoque disponível (ver cálculo de disponibilidade
  abaixo), filtro por categoria, página de produto com seletor de variação
  (mesmo padrão de combinação do Fundação/PDV) e galeria das até 5 fotos.
- Adicionar ao carrinho exige login (`exigirClienteLogado()`). Cada clique
  em "adicionar" cria ou reaproveita a `venda` "Reservado"/`origem='loja'`
  **deste cliente** (mesmo padrão de "uma venda em andamento por
  operador" do PDV, mas aqui por cliente) e insere um `itens_venda` com
  retrato (nome, descrição da combinação, preço) — snapshot, sem FK,
  idêntico ao PDV.
- **Estoque nunca é debitado no carrinho** — só é debitado de verdade
  dentro de `finalizarVenda()`, no momento da confirmação de pagamento
  (mesma trava `FOR UPDATE` do PDV, reaproveitada sem alteração). Isso já
  garante, por si só, que o estoque real nunca fica negativo, não importa
  quantos carrinhos concorrentes existam.
- Para reduzir (não eliminar — ver "Tratamento de erros") o caso chato de
  "paguei mas não consegui" quando dois clientes disputam a última unidade,
  a quantidade **disponível pra novo cliente adicionar** é calculada na
  hora, subtraindo do estoque real a soma das reservas "Reservado" ainda
  válidas (não expiradas) de **outros** clientes para aquela combinação —
  sem precisar de uma coluna separada de "estoque reservado" pra manter
  sincronizada.

## Expiração do carrinho (sem CRON)

`config_loja` (Fundação) ganha:

```sql
ALTER TABLE config_loja ADD COLUMN prazo_reserva_minutos INT NOT NULL DEFAULT 15;
```

Uma função `liberarReservasExpiradas($pdo)` (novo `includes/loja.php`) é
chamada no início de toda página/endpoint da loja que lê estoque ou
carrinho (catálogo, produto, carrinho, checkout). Ela só faz:

```sql
UPDATE vendas SET status = 'Cancelado'
WHERE status = 'Reservado' AND origem = 'loja'
  AND data_venda < DATE_SUB(NOW(), INTERVAL :prazo MINUTE)
```

Como o estoque nunca foi debitado pra uma venda "Reservado", cancelar não
precisa devolver nada — só libera a linha pra parar de contar no cálculo de
disponibilidade acima, e libera o cliente pra começar um carrinho novo. O
cliente vê uma contagem regressiva no carrinho, calculada no navegador a
partir de `data_venda + prazo_reserva_minutos` (sem depender de nenhum
relógio de servidor em tempo real).

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

- Estoque insuficiente ao tentar adicionar ao carrinho → mensagem clara,
  nada é adicionado.
- Carrinho vencido (expirado) ao tentar prosseguir pro checkout → aviso,
  itens somem do carrinho, cliente precisa adicionar de novo (e a
  disponibilidade já reflete a liberação, já que
  `liberarReservasExpiradas()` roda antes de qualquer leitura de estoque).
- **Caso raro que continua possível mesmo com a checagem de disponibilidade**:
  dois clientes conseguem, quase ao mesmo tempo, colocar no carrinho a
  última unidade de uma combinação (a checagem de disponibilidade não é
  atômica) e os dois pagam. `finalizarVenda()` (reaproveitada do PDV) só
  deixa o primeiro pagamento confirmado passar — o segundo cliente pagou de
  verdade no Mercado Pago mas a venda não finaliza (estoque insuficiente).
  Como esse caso não tem, ainda, um fluxo de estorno automático, a página
  de erro do checkout deve deixar claro que, se isso acontecer, a loja
  entrará em contato pra reembolsar ou repor — tratado manualmente por
  enquanto (é raro, e automatizar estorno é escopo pra outro momento).
- Falha na geração da preferência do Mercado Pago (loja não conectada,
  erro de rede) → mensagem amigável, carrinho continua intacto.
- Nonce/assinatura de webhook inválidos → mesmo tratamento do PDV (loga e
  responde 200, nunca deixa o Mercado Pago re-tentar indefinidamente).

## Testes

Sem suíte automatizada, mesmo padrão dos sub-projetos anteriores: validação
manual rodando o sistema de verdade (servidor PHP local + MySQL). O fluxo
completo de pagamento real do Mercado Pago (preferência, checkout hospedado,
webhook) só pode ser testado de ponta a ponta em produção, com a loja já
conectada ao Mercado Pago (pendência já conhecida do sub-projeto 2).
