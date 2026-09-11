# Sub-projeto 4: Linha de Crédito — Sistema Veronica

Status: aprovado para virar plano de implementação
Data: 2026-09-10

## Contexto

O Sistema Veronica é um sistema de gestão para uma loja de brechó, decomposto
em 4 sub-projetos:

1. **Fundação** — implementado, revisado e em produção.
2. **PDV / Frente de Caixa** — implementado, revisado e em produção.
3. **Loja Online / App do Cliente** — implementado, revisado e em produção.
4. **Linha de Crédito** (este documento)

Este documento cobre: crédito pré-aprovado por cliente como forma de
pagamento (tanto no PDV quanto na Loja Online), e uma área onde o cliente
acompanha e paga sua dívida — presencialmente ou pelo Mercado Pago.

## Modelo de negócio

- Cada cliente tem um **limite de crédito** (`clientes.limite_credito`),
  definido manualmente pelo Admin (por padrão `0` — sem crédito liberado
  até o Admin configurar).
- Comprar "fiado" (no PDV ou na Loja Online) aumenta o **saldo devedor**
  do cliente (`clientes.saldo_devedor`), até o limite de crédito disponível
  (`limite_credito - saldo_devedor`).
- **Sem juros, sem multa, sem data de vencimento** — o cliente deve
  exatamente o valor que comprou, paga quando puder.
- O pagamento da dívida pode ser feito **presencialmente no PDV** (qualquer
  funcionário registra) ou **online pelo Mercado Pago** (o próprio cliente,
  na área "Meus débitos").

## Decisão-chave: saldo corrente + ledger de auditoria

`clientes.saldo_devedor` é a fonte da verdade do saldo atual, atualizado
sempre através de um `UPDATE` atômico com guarda na própria cláusula
`WHERE` — o mesmo padrão já usado e validado neste projeto para a reserva
de estoque da Loja Online (`estoque_reservado`):

```sql
-- Ao vender fiado (aumentar a dívida):
UPDATE clientes
SET saldo_devedor = saldo_devedor + :valor
WHERE id_cliente = :id AND (saldo_devedor + :valor) <= limite_credito
```

Se `0` linhas forem afetadas, não havia limite disponível — a operação que
chamou esse `UPDATE` (venda fiada) falha ali mesmo, sem brecha de corrida.

```sql
-- Ao registrar um pagamento da dívida (diminuir a dívida):
UPDATE clientes
SET saldo_devedor = GREATEST(0, saldo_devedor - :valor)
WHERE id_cliente = :id AND saldo_devedor >= :valor
```

Uma tabela nova, `movimentos_credito`, guarda o extrato (toda compra fiada
e todo pagamento) — é só auditoria/histórico, nunca a fonte da verdade do
saldo. Toda escrita em `movimentos_credito` acontece **na mesma
transação** que o `UPDATE` do saldo, então os dois nunca podem
dessincronizar.

## Schema

```sql
ALTER TABLE clientes
    ADD COLUMN limite_credito DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN saldo_devedor DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE movimentos_credito (
    id_movimento INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    tipo ENUM('compra', 'pagamento') NOT NULL,
    status ENUM('Confirmado', 'Pendente', 'Cancelado') NOT NULL DEFAULT 'Confirmado',
    valor DECIMAL(10,2) NOT NULL,
    id_venda INT NULL,                -- preenchido quando tipo='compra'
    forma_pagamento VARCHAR(50) NULL, -- preenchido quando tipo='pagamento'
                                       -- (Dinheiro/Débito/Crédito/Pix manual no PDV,
                                       -- ou 'Mercado Pago' no pagamento online)
    id_pagamento_mp VARCHAR(50) NULL, -- preenchido quando pago online via MP
    criado_por INT NULL,              -- id_usuario do funcionário, NULL = cliente online
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`status` existe só para o caso de pagamento online (que passa por um
checkout do Mercado Pago antes de confirmar). Uma compra fiada (tipo
'compra', sempre presencial ou pela loja online com decisão imediata) e um
pagamento presencial no PDV (tipo 'pagamento', registrado direto por um
funcionário) sempre entram já como `'Confirmado'` — só o pagamento online
via Mercado Pago passa por `'Pendente'` até o webhook confirmar.

## PDV: venda fiada

- `caixa/pagamento.php` ganha a opção **"Linha de Crédito"** no `<select>`
  de forma de pagamento. Essa opção só deve ser de fato utilizável quando a
  venda já tem um `id_cliente` vinculado (a vinculação já existe no PDV,
  via `caixa/ajax/vincular_cliente.php`) — sem cliente vinculado, a opção
  aparece desabilitada no `<select>` com uma dica ("vincule um cliente
  primeiro").
- O operador adiciona "Linha de Crédito: R$ X" à lista de pagamentos do
  jeito que já funciona hoje para Dinheiro/Débito/Crédito — sem checagem
  ao vivo, client-side.
- A checagem de verdade acontece dentro de `finalizarVenda()`
  (`includes/caixa.php`), no momento de finalizar: soma-se todo valor com
  `forma === 'Linha de Crédito'` num único total (se houver mais de uma
  linha, o que é incomum mas possível), roda o `UPDATE` atômico acima. Se
  afetar `0` linhas, a finalização inteira falha (nada fica parcialmente
  salvo — mesma garantia transacional que a função já tem hoje) com a
  mensagem **"Limite de crédito insuficiente."**. Se tiver sucesso, grava
  um `movimentos_credito` (tipo='compra', `status='Confirmado'`,
  `id_venda`, `criado_por` = operador) na mesma transação.
- Vender fiado sem `id_cliente` na venda é rejeitado com **"É necessário
  vincular um cliente para vender fiado."**
- `caixa/ajax/finalizar_venda.php` precisa adicionar `'Linha de Crédito'`
  ao array `$formasValidas` (hoje: `['Dinheiro', 'Débito', 'Crédito',
  'Pix']`).

## Tela da dona: gerenciar crédito do cliente

Em `clientes/detalhe.php` (já existe, hoje só mostra dados cadastrais):

- Mostra limite de crédito, saldo devedor e crédito disponível.
- Campo para editar o limite — **só Admin** pode mudar (Funcionário só
  visualiza, mesmo padrão de `exigirAdmin()` já usado em outras telas
  administrativas do projeto).
- Extrato: lista de `movimentos_credito` do cliente (compras fiadas e
  pagamentos, com data, valor e status), mais recente primeiro.
- Formulário "Registrar pagamento" — qualquer funcionário logado pode usar
  (cliente paga parte da dívida em dinheiro/cartão/pix na loja), roda o
  `UPDATE` atômico de abatimento e grava o movimento como
  `tipo='pagamento'`, `status='Confirmado'`, `criado_por` = quem
  registrou. Rejeita se `valor > saldo_devedor` atual com **"Valor maior
  que a dívida atual."**

## Loja Online: crédito no checkout

- `loja/checkout.php` mostra uma segunda opção de pagamento, junto da já
  existente "Pagar com Mercado Pago": **"Pagar com minha Linha de
  Crédito"** — só aparece se `clientes.limite_credito > 0`. Mostra o
  crédito disponível; se for menor que o total do carrinho (itens +
  entrega), a opção fica visível mas desabilitada, com o motivo ("crédito
  insuficiente: disponível R$ X"). As duas opções são alternativas — o
  cliente escolhe uma ou outra para pagar o pedido inteiro; não há divisão
  de um mesmo pedido entre crédito e Mercado Pago nesta fase.
- Ao escolher crédito: diferente do Mercado Pago (que redireciona pro
  checkout hospedado e confirma por webhook), essa é uma decisão imediata
  — um novo endpoint (`loja/ajax/finalizar_credito.php`) monta a linha de
  entrega em `itens_venda` (mesmo padrão de `id_produto_variacao = NULL`
  já usado no checkout via Mercado Pago) e chama `finalizarVenda()`
  diretamente com `forma => 'Linha de Crédito'`. A checagem atômica de
  limite acontece dentro da própria `finalizarVenda()`, igual ao PDV.
- Se a finalização for bem-sucedida: venda já vira `'Pago'` na hora,
  redireciona para `loja/pedido_status.php` (já existe, já mostra estado
  "Pago"). Se falhar (limite insuficiente): mostra erro, o carrinho e a
  reserva de estoque continuam intactos (nada foi destruído).

## "Meus débitos" do cliente

Página nova `loja/minha_divida.php` (exige `exigirClienteLogado()`):

- Mostra saldo devedor, limite de crédito, crédito disponível e o extrato
  (`movimentos_credito` do cliente — mesma fonte de dados da tela da
  dona, mas sem os dados de outros clientes).
- Se `saldo_devedor > 0`: formulário para pagar — cliente escolhe um
  valor (validado no servidor: não pode exceder o `saldo_devedor` atual
  no momento da geração da preferência).
- Gera uma preferência do Mercado Pago Checkout Pro (reaproveitando
  `mpConfig()`/`mpChamarApi()` de `includes/mp_client.php`), restrita a
  Pix e cartão (`excluded_payment_types: [{id: 'ticket'}]`, mesma decisão
  já tomada para o checkout de produtos — sem boleto), com
  `marketplace_fee`/`sponsor_id` igual a todo outro pagamento do projeto.
- Antes de redirecionar, grava um `movimentos_credito` com
  `tipo='pagamento'`, `status='Pendente'`, `forma_pagamento='Mercado
  Pago'` — é essa linha que o webhook vai confirmar.
- Webhook próprio e separado do de produtos: `loja/api/notificacao_divida_mp.php`
  (mesma técnica de validação de assinatura X-Signature do webhook de
  produtos, `external_reference` no formato `'divida_' . id_movimento`).
  Ao confirmar aprovação: `UPDATE movimentos_credito SET status =
  'Confirmado' WHERE id_movimento = :id AND status = 'Pendente'` — só se
  essa linha afetar `1` linha (evita processar a mesma notificação duas
  vezes), abate `saldo_devedor` com o `UPDATE` atômico já descrito
  (`GREATEST(0, saldo_devedor - valor)`, sem o piso dar problema mesmo que
  o saldo tenha mudado entre a geração da preferência e a confirmação).
- Se a notificação trouxer um status final negativo do Mercado Pago
  (rejeitado/cancelado) o movimento vira `status = 'Cancelado'` — não
  abate o saldo devedor. Se a notificação simplesmente nunca chegar, o
  movimento fica `'Pendente'` indefinidamente (não existe expiração
  automática para pagamento de dívida, diferente da reserva de carrinho).
  Em ambos os casos fica visível no extrato da dona
  (`clientes/detalhe.php`) para ela resolver manualmente, e o cliente vê
  "Pagamento não confirmado. A loja vai verificar e ajustar seu saldo."
  na mesma tela.

## Tratamento de erros

- Venda fiada sem cliente vinculado (PDV) → rejeitada antes de qualquer
  escrita.
- Limite de crédito insuficiente (PDV ou Loja Online) → `finalizarVenda()`
  rejeita tudo, nada fica parcialmente salvo.
- Pagamento de dívida (presencial ou online) maior que o saldo devedor
  atual → rejeitado no momento do registro/da geração da preferência.
- Falha de confirmação após o Mercado Pago capturar o pagamento da dívida
  → fica `'Pendente'` no extrato, sem perda de dinheiro (estorno fica a
  cargo da dona, igual ao tratamento já existente para produtos).
- Erros de assinatura/nonce do webhook → mesmo tratamento dos webhooks
  já existentes (loga e responde 200, nunca deixa o Mercado Pago
  re-tentar indefinidamente).

## Testes

Sem suíte automatizada, mesmo padrão dos sub-projetos anteriores:
validação manual rodando o sistema de verdade (servidor PHP local +
MySQL). O fluxo completo de pagamento real do Mercado Pago (pagamento de
dívida) só pode ser testado de ponta a ponta em produção, com a loja
conectada ao Mercado Pago — pendência já conhecida, compartilhada com os
sub-projetos anteriores.
