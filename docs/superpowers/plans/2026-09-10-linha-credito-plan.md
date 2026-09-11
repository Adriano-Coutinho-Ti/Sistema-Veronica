# Linha de Crédito Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let clients buy on store credit (a pre-approved limit per client) at the PDV and in the Loja Online, and let them track and pay down what they owe — in person or via Mercado Pago.

**Architecture:** Adds a running `saldo_devedor`/`limite_credito` pair on `clientes`, updated exclusively through atomic guarded `UPDATE`s (the same pattern already proven for `produto_variacoes.estoque_reservado` in the Loja Online sub-project), plus a `movimentos_credito` audit ledger written in the same transaction as every balance change. "Linha de Crédito" becomes a valid `forma_pagamento` inside the existing shared `finalizarVenda()` choke point (PDV sub-project), so a credit sale is a completed sale like any other — payment just isn't cash-in-hand. Debt repayment is a separate, independent flow (PDV staff registers it directly; Loja Online client pays via a dedicated Mercado Pago preference + its own webhook).

**Tech Stack:** PHP 8.5, MySQL 8 (InnoDB, utf8mb4), PDO, native PHP sessions, Mercado Pago REST API (reusing `includes/mp_client.php` from the PDV sub-project).

**Spec:** [docs/superpowers/specs/2026-09-10-linha-credito-design.md](../specs/2026-09-10-linha-credito-design.md)

## Global Constraints

- No automated tests — every task's verification step is a manual procedure (curl/direct PHP calls + direct DB queries against a real running server) to run and observe, matching every prior sub-project's convention.
- Money fields are `DECIMAL(10,2)`; every place a Brazilian comma-decimal string enters PHP from a form, normalize with `str_replace(',', '.', $value)` **before** the `(float)` cast.
- **No interest, no late fees, no due date.** The client owes exactly what they spent on credit, nothing more, with no formal deadline.
- **The credit balance is absolute, not probabilistic**: `saldo_devedor` changes only through a single atomic `UPDATE` whose `WHERE` clause is the guard (increase: `WHERE (saldo_devedor + :valor) <= limite_credito`; decrease: `WHERE saldo_devedor >= :valor`). If it affects 0 rows, the operation failed, full stop, nothing else happens.
- Every write to `movimentos_credito` happens in the **same transaction** as the `clientes.saldo_devedor` update it corresponds to — the two must never be able to diverge.
- Only **Admin** can change a client's `limite_credito` (any logged-in staff can view it and register a debt payment).
- A credit sale at the PDV requires the venda to already have a linked client (`vendas.id_cliente` set) — reject otherwise.
- In the Loja Online checkout, Mercado Pago and Linha de Crédito are **alternative** payment methods for the whole order — never combined/split within one order in this phase.
- A debt payment (presencial or online) can never exceed the client's current `saldo_devedor`.
- Every Mercado Pago preference created in this sub-project excludes boleto (`excluded_payment_types: [{id: 'ticket'}]`) and includes `marketplace_fee` (1%, `ceil($valor * 0.01 * 100) / 100`) and `sponsor_id: 194420711` — same convention as every other Mercado Pago charge in this project.
- All PDO/money/escaping conventions from prior sub-projects apply: bound parameters everywhere (use distinct placeholder names for repeated values in one query, e.g. `:valor`/`:valor2`, matching this project's established defensive convention), `htmlspecialchars()` on all dynamic HTML output, exceptions propagate rather than being echoed raw.

---

## File Structure

```
sql/schema_credito.sql
includes/caixa.php          (modify — finalizarVenda() gains credit-limit check)
includes/loja.php           (modify — add definirEntregaDaVenda() helper)
caixa/pagamento.php         (modify — add "Linha de Crédito" option)
caixa/ajax/finalizar_venda.php  (modify — add to forma allowlist)
clientes/detalhe.php        (modify — credit management UI, self-submitting)
loja/checkout.php           (modify — add "Pagar com Linha de Crédito" button)
loja/ajax/gerar_checkout.php    (modify — use the new definirEntregaDaVenda() helper)
loja/ajax/finalizar_credito.php (new)
loja/minha_divida.php       (new)
loja/ajax/gerar_checkout_divida.php (new)
loja/api/notificacao_divida_mp.php  (new)
```

---

### Task 1: Database schema + `finalizarVenda()` credit-limit check

**Files:**
- Create: `sql/schema_credito.sql`
- Modify: `includes/caixa.php` (inside `finalizarVenda()`)
- Modify: `caixa/ajax/finalizar_venda.php`

**Interfaces:**
- Consumes: `$pdo`, existing `finalizarVenda(PDO $pdo, int $id_venda, array $pagamentos, ?string $id_pagamento_mp = null): array` signature (unchanged)
- Produces: `clientes.limite_credito`/`saldo_devedor` columns, `movimentos_credito` table — consumed by every later task in this plan. `finalizarVenda()` now accepts `['forma' => 'Linha de Crédito', 'valor' => float]` as a valid payment entry — consumed by Task 3 (PDV) and Task 5 (Loja Online)

- [ ] **Step 1: Write the SQL migration**

Create `sql/schema_credito.sql`:

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
    id_venda INT NULL,
    forma_pagamento VARCHAR(50) NULL,
    id_pagamento_mp VARCHAR(50) NULL,
    criado_por INT NULL,
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- [ ] **Step 2: Run the migration and verify**

Run: `mysql -u root sistema_veronica < sql/schema_credito.sql`
Verify: `mysql -u root sistema_veronica -e "DESCRIBE clientes"` — confirm `limite_credito`/`saldo_devedor` present, both `DEFAULT 0`. `mysql -u root sistema_veronica -e "DESCRIBE movimentos_credito"` — confirm all columns. Confirm existing `clientes` rows are untouched: `mysql -u root sistema_veronica -e "SELECT id_cliente, nome, limite_credito, saldo_devedor FROM clientes LIMIT 5"` — every row shows `0.00`/`0.00`.

- [ ] **Step 3: Extend `finalizarVenda()` to handle `'Linha de Crédito'` payments**

Open `includes/caixa.php`. Find the line:

```php
$stmt = $pdo->prepare('SELECT valor_total, status FROM vendas WHERE id_venda = :id FOR UPDATE');
```

Change it to also fetch `id_cliente` and `id_usuario` (both already columns on `vendas`):

```php
$stmt = $pdo->prepare('SELECT valor_total, status, id_cliente, id_usuario FROM vendas WHERE id_venda = :id FOR UPDATE');
```

Find the stock-debit loop (it ends with):

```php
        foreach ($quantidadePorVariacao as $id_pv => $quantidadeTotal) {
            $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque - :qtd, estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
                ->execute([':qtd' => $quantidadeTotal, ':id' => $id_pv]);
        }

        $formas = [];
```

Insert a new block between those two pieces (right after the stock-debit loop, right before `$formas = [];`):

```php
        foreach ($quantidadePorVariacao as $id_pv => $quantidadeTotal) {
            $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque - :qtd, estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
                ->execute([':qtd' => $quantidadeTotal, ':id' => $id_pv]);
        }

        // Linha de Crédito não é dinheiro recebido — é uma promessa de pagamento que
        // aumenta o saldo devedor do cliente vinculado à venda, até o limite liberado.
        $valorCredito = 0.0;
        foreach ($pagamentos as $pag) {
            if ($pag['forma'] === 'Linha de Crédito') {
                $valorCredito += (float) $pag['valor'];
            }
        }

        if ($valorCredito > 0) {
            if (!$venda['id_cliente']) {
                throw new Exception('É necessário vincular um cliente para vender fiado.');
            }

            $id_cliente_credito = (int) $venda['id_cliente'];

            $aumentouCredito = $pdo->prepare(
                'UPDATE clientes SET saldo_devedor = saldo_devedor + :valor
                 WHERE id_cliente = :id AND (saldo_devedor + :valor2) <= limite_credito'
            );
            $aumentouCredito->execute([':valor' => $valorCredito, ':valor2' => $valorCredito, ':id' => $id_cliente_credito]);

            if ($aumentouCredito->rowCount() === 0) {
                throw new Exception('Limite de crédito insuficiente.');
            }

            $pdo->prepare(
                "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, id_venda, criado_por)
                 VALUES (:ic, 'compra', 'Confirmado', :valor, :iv, :criado_por)"
            )->execute([
                ':ic' => $id_cliente_credito,
                ':valor' => $valorCredito,
                ':iv' => $id_venda,
                ':criado_por' => $venda['id_usuario'],
            ]);
        }

        $formas = [];
```

Do not change anything else in `finalizarVenda()` — the transaction structure, the `FOR UPDATE` locks, the `ksort()` ordering, the payment-sufficiency check, all stay exactly as they are. `$venda['id_usuario']` is `NULL` for Loja Online sales (correctly recorded as "cliente fez online, nenhum funcionário") and the real operator's id for PDV sales.

- [ ] **Step 4: Add `'Linha de Crédito'` to the PDV's payment allowlist**

Open `caixa/ajax/finalizar_venda.php`. Find:

```php
$formasValidas = ['Dinheiro', 'Débito', 'Crédito', 'Pix'];
```

Change to:

```php
$formasValidas = ['Dinheiro', 'Débito', 'Crédito', 'Pix', 'Linha de Crédito'];
```

- [ ] **Step 5: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l includes/caixa.php` and `-l caixa/ajax/finalizar_venda.php` — expect no syntax errors.

Set up a real test: pick a real client (or create one), set their `limite_credito` directly (`UPDATE clientes SET limite_credito = 50 WHERE id_cliente = <id>`). Create a real PDV venda linked to that client with `valor_total = 30` (use the existing PDV flow: abrir caixa, iniciar venda, adicionar item, vincular cliente). Call `finalizarVenda($pdo, $id_venda, [['forma' => 'Linha de Crédito', 'valor' => 30]])` via a one-off PHP script using the real `$pdo` — confirm `success: true`, confirm `clientes.saldo_devedor` is now `30`, confirm a `movimentos_credito` row exists (`tipo='compra'`, `status='Confirmado'`, `valor=30`, `id_venda` set, `criado_por` = the real operator's `id_usuario`).

Test the limit guard: with `saldo_devedor=30`/`limite_credito=50` (20 available), attempt another credit sale for `40` — confirm `success: false` with message "Limite de crédito insuficiente.", and confirm `saldo_devedor` did NOT change.

Test the no-client guard: create a venda with NO client vinculado, attempt `finalizarVenda()` with a `'Linha de Crédito'` payment — confirm rejection with "É necessário vincular um cliente para vender fiado." and confirm no stock was debited (transaction rolled back entirely).

Reset test data afterward (`UPDATE clientes SET limite_credito = 0, saldo_devedor = 0 WHERE id_cliente = <id>`, delete the test `movimentos_credito` rows).

- [ ] **Step 6: Commit**

```bash
git add sql/schema_credito.sql includes/caixa.php caixa/ajax/finalizar_venda.php
git commit -m "feat: add credit-limit schema and wire Linha de Crédito into finalizarVenda()"
```

---

### Task 2: Extract `definirEntregaDaVenda()` helper, refactor `gerar_checkout.php`

**Files:**
- Modify: `includes/loja.php` (add a function)
- Modify: `loja/ajax/gerar_checkout.php` (refactor to use it)

**Interfaces:**
- Consumes: `recalcularTotalVenda(PDO $pdo, int $id_venda): float` (existing, `includes/caixa.php`)
- Produces: `definirEntregaDaVenda(PDO $pdo, int $id_venda, array $entrega): float` where `$entrega` has keys `id_entrega`, `nome`, `custo` — consumed by Task 5 (`loja/ajax/finalizar_credito.php`)

- [ ] **Step 1: Add `definirEntregaDaVenda()` to `includes/loja.php`**

Add this function to `includes/loja.php` (anywhere after the existing functions):

```php
/**
 * Grava/substitui a linha de entrega da venda — um item sem produto vinculado
 * (id_produto_variacao NULL), que finalizarVenda()/devolverReservaDaVenda() já
 * ignoram — e recalcula o total. Reaproveitado tanto pelo checkout via Mercado
 * Pago quanto pelo pagamento direto com Linha de Crédito, pra nunca duplicar essa
 * lógica entre os dois fluxos.
 */
function definirEntregaDaVenda(PDO $pdo, int $id_venda, array $entrega): float
{
    $pdo->prepare("DELETE FROM itens_venda WHERE id_venda = :iv AND id_produto_variacao IS NULL AND nome_produto = 'Entrega'")
        ->execute([':iv' => $id_venda]);

    if ((float) $entrega['custo'] > 0) {
        $pdo->prepare(
            'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
             VALUES (:iv, :nome, :desc, NULL, 1, :preco, :subtotal)'
        )->execute([
            ':iv' => $id_venda,
            ':nome' => 'Entrega',
            ':desc' => $entrega['nome'],
            ':preco' => (float) $entrega['custo'],
            ':subtotal' => (float) $entrega['custo'],
        ]);
    }

    $pdo->prepare('UPDATE vendas SET id_entrega = :ie WHERE id_venda = :iv')
        ->execute([':ie' => (int) $entrega['id_entrega'], ':iv' => $id_venda]);

    return recalcularTotalVenda($pdo, $id_venda);
}
```

- [ ] **Step 2: Refactor `gerar_checkout.php` to call it**

Open `loja/ajax/gerar_checkout.php`. Find this block:

```php
// Remove qualquer linha de entrega anterior (cliente pode ter voltado e trocado a forma de
// entrega antes de tentar pagar de novo) e insere a atual como um item sem produto vinculado
// — id_produto_variacao NULL, que finalizarVenda()/devolverReservaDaVenda() já ignoram.
$pdo->prepare("DELETE FROM itens_venda WHERE id_venda = :iv AND id_produto_variacao IS NULL AND nome_produto = 'Entrega'")
    ->execute([':iv' => $id_venda]);

if ((float) $entrega['custo'] > 0) {
    $pdo->prepare(
        'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
         VALUES (:iv, :nome, :desc, NULL, 1, :preco, :subtotal)'
    )->execute([
        ':iv' => $id_venda,
        ':nome' => 'Entrega',
        ':desc' => $entrega['nome'],
        ':preco' => (float) $entrega['custo'],
        ':subtotal' => (float) $entrega['custo'],
    ]);
}

$pdo->prepare('UPDATE vendas SET id_entrega = :ie WHERE id_venda = :iv')
    ->execute([':ie' => $id_entrega, ':iv' => $id_venda]);

$valorTotalComEntrega = recalcularTotalVenda($pdo, $id_venda);
```

Replace it with:

```php
$valorTotalComEntrega = definirEntregaDaVenda($pdo, $id_venda, $entrega);
```

(`$entrega` already has `id_entrega`, `nome`, `custo` from the earlier `SELECT id_entrega, nome, tipo, custo FROM formas_entrega ...` in this same file — no other change needed.)

- [ ] **Step 3: Verify manually (regression test)**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l includes/loja.php` and `-l loja/ajax/gerar_checkout.php` — expect no syntax errors.

Run the existing Loja Online flow end-to-end: log in as a client, add a product to the cart, go to checkout, pick a delivery method with a non-zero cost, submit "Pagar com Mercado Pago" (the not-connected fallback path is fine for this check, same as prior sub-project testing). Confirm via direct DB query that `itens_venda` now has the "Entrega" row with the right `nome_produto`/`descricao_combinacao`/`subtotal`, `vendas.id_entrega` is set, and `vendas.valor_total` correctly equals items + delivery — i.e., confirm the refactor produces byte-identical behavior to before. Then add another item to the same cart afterward and confirm the delivery row/total logic still works correctly on a second pass (the delete-then-insert behavior).

- [ ] **Step 4: Commit**

```bash
git add includes/loja.php loja/ajax/gerar_checkout.php
git commit -m "refactor: extract definirEntregaDaVenda() helper, shared by Mercado Pago and Linha de Crédito checkout"
```

---

### Task 3: PDV — "Linha de Crédito" payment option

**Files:**
- Modify: `caixa/pagamento.php`

**Interfaces:**
- Consumes: `finalizarVenda()` accepting `'Linha de Crédito'` (Task 1), `$venda['id_cliente']` (already fetched by this file's existing query)
- Produces: nothing new consumed by later tasks (this is the PDV-side UI only)

- [ ] **Step 1: Fetch the linked client's credit info**

Open `caixa/pagamento.php`. Find:

```php
if (!$venda) {
    header('Location: /caixa/index.php');
    exit;
}
```

Add right after it:

```php
$clienteVinculado = null;
$creditoDisponivel = 0.0;
if ($venda['id_cliente']) {
    $stmtCli = $pdo->prepare('SELECT nome, limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
    $stmtCli->execute([':id' => $venda['id_cliente']]);
    $clienteVinculado = $stmtCli->fetch();
    if ($clienteVinculado) {
        $creditoDisponivel = (float) $clienteVinculado['limite_credito'] - (float) $clienteVinculado['saldo_devedor'];
    }
}
```

- [ ] **Step 2: Add the option to the payment-method `<select>`**

Find:

```html
        <select id="forma-pagamento">
            <option value="Dinheiro">Dinheiro</option>
            <option value="Débito">Débito</option>
            <option value="Crédito">Crédito</option>
            <option value="Pix">Pix (QR Code)</option>
        </select>
```

Change to:

```html
        <select id="forma-pagamento">
            <option value="Dinheiro">Dinheiro</option>
            <option value="Débito">Débito</option>
            <option value="Crédito">Crédito</option>
            <option value="Pix">Pix (QR Code)</option>
            <option value="Linha de Crédito" <?= !$clienteVinculado ? 'disabled' : '' ?>>
                Linha de Crédito<?= $clienteVinculado
                    ? ' (disponível: R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')'
                    : ' (vincule um cliente primeiro)' ?>
            </option>
        </select>
```

No other change to this file is needed — the existing `atualizarResumo()`/`btn-adicionar-pagamento`/`btn-finalizar` JS already works generically for any `forma` string value.

- [ ] **Step 3: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l caixa/pagamento.php` — expect no syntax errors.

Open the payment page for a venda with NO client vinculado — confirm the "Linha de Crédito" option is visibly disabled and shows "(vincule um cliente primeiro)". Vincule a client with a real `limite_credito` set (reuse Task 1's test client or set one up again), reload — confirm the option is now enabled and shows the real available credit. Select it, add a payment equal to the venda's total, click "Finalizar venda" — confirm it completes successfully end-to-end (venda becomes "Pago", `clientes.saldo_devedor` increases by that amount, a `movimentos_credito` row is created) using the real PDV UI this time, not a one-off script.

- [ ] **Step 4: Commit**

```bash
git add caixa/pagamento.php
git commit -m "feat: add Linha de Crédito payment option to the PDV"
```

---

### Task 4: Admin/staff credit management UI

**Files:**
- Modify: `clientes/detalhe.php`

**Interfaces:**
- Consumes: `$pdo`, `clientes.limite_credito`/`saldo_devedor` (Task 1), `movimentos_credito` (Task 1)
- Produces: nothing new consumed by later tasks (this is the staff-facing UI only)

- [ ] **Step 1: Rewrite `clientes/detalhe.php`**

Replace the entire contents of `clientes/detalhe.php` with:

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    echo 'Cliente não encontrado.';
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_limite') {
    if (($_SESSION['perfil'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }

    $novoLimite = (float) str_replace(',', '.', $_POST['limite_credito'] ?? '0');
    if ($novoLimite < 0) {
        $erro = 'Limite inválido.';
    } else {
        $pdo->prepare('UPDATE clientes SET limite_credito = :limite WHERE id_cliente = :id')
            ->execute([':limite' => $novoLimite, ':id' => $id]);
        $sucesso = 'Limite atualizado.';
        $cliente['limite_credito'] = $novoLimite;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar_pagamento') {
    $valor = (float) str_replace(',', '.', $_POST['valor'] ?? '0');
    $forma = trim($_POST['forma_pagamento'] ?? '');
    $formasValidas = ['Dinheiro', 'Débito', 'Crédito', 'Pix'];

    if ($valor <= 0 || !in_array($forma, $formasValidas, true)) {
        $erro = 'Dados de pagamento inválidos.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtAbater = $pdo->prepare(
                'UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor)
                 WHERE id_cliente = :id AND saldo_devedor >= :valor2'
            );
            $stmtAbater->execute([':valor' => $valor, ':valor2' => $valor, ':id' => $id]);

            if ($stmtAbater->rowCount() === 0) {
                throw new Exception('Valor maior que a dívida atual.');
            }

            $pdo->prepare(
                "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, forma_pagamento, criado_por)
                 VALUES (:ic, 'pagamento', 'Confirmado', :valor, :forma, :criado_por)"
            )->execute([
                ':ic' => $id,
                ':valor' => $valor,
                ':forma' => $forma,
                ':criado_por' => $_SESSION['id_usuario'],
            ]);

            $pdo->commit();
            $sucesso = 'Pagamento registrado com sucesso.';

            $stmt2 = $pdo->prepare('SELECT saldo_devedor FROM clientes WHERE id_cliente = :id');
            $stmt2->execute([':id' => $id]);
            $cliente['saldo_devedor'] = $stmt2->fetchColumn();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = $e->getMessage();
        }
    }
}

$stmtExtrato = $pdo->prepare(
    'SELECT mc.*, u.nome AS nome_funcionario
     FROM movimentos_credito mc
     LEFT JOIN usuarios u ON u.id_usuario = mc.criado_por
     WHERE mc.id_cliente = :id
     ORDER BY mc.data_movimento DESC'
);
$stmtExtrato->execute([':id' => $id]);
$extrato = $stmtExtrato->fetchAll();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($cliente['nome']) ?></title></head>
<body>
    <h1><?= htmlspecialchars($cliente['nome']) ?></h1>
    <p>WhatsApp: <?= htmlspecialchars($cliente['whatsapp']) ?></p>
    <p>E-mail: <?= htmlspecialchars($cliente['email'] ?? '—') ?></p>
    <p>Endereço: <?= htmlspecialchars($cliente['endereco'] ?? '—') ?></p>

    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p style="color:green;"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <h2>Linha de Crédito</h2>
    <p>Limite: R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></p>
    <p>Saldo devedor: R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></p>
    <p>Crédito disponível: R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></p>

    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar_limite">
        <label>Novo limite de crédito
            <input type="text" name="limite_credito" value="<?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?>">
        </label>
        <button type="submit">Atualizar limite</button>
    </form>
    <?php endif; ?>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h3>Registrar pagamento da dívida</h3>
    <form method="post">
        <input type="hidden" name="acao" value="registrar_pagamento">
        <label>Valor recebido
            <input type="text" name="valor" placeholder="0,00">
        </label>
        <label>Forma de pagamento
            <select name="forma_pagamento">
                <option value="Dinheiro">Dinheiro</option>
                <option value="Débito">Débito</option>
                <option value="Crédito">Crédito</option>
                <option value="Pix">Pix</option>
            </select>
        </label>
        <button type="submit">Registrar pagamento</button>
    </form>
    <?php endif; ?>

    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p>Nenhum movimento de crédito ainda.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($extrato as $mov): ?>
        <li>
            <?= htmlspecialchars($mov['data_movimento']) ?> —
            <?= $mov['tipo'] === 'compra' ? 'Compra fiada' : 'Pagamento' ?>
            (<?= htmlspecialchars($mov['status']) ?>) —
            R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
            <?= $mov['forma_pagamento'] ? ' via ' . htmlspecialchars($mov['forma_pagamento']) : '' ?>
            <?= $mov['nome_funcionario'] ? ' (registrado por ' . htmlspecialchars($mov['nome_funcionario']) . ')' : ' (cliente, online)' ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p><a href="/clientes/lista.php">Voltar</a></p>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l clientes/detalhe.php` — expect no syntax errors.

As Admin: open a client's page, confirm limite/saldo/disponível render, update the limit via the form, confirm it persists (reload, check DB). As Funcionário (non-Admin): confirm the limit-edit form does NOT render, and confirm a direct POST of `acao=atualizar_limite` is rejected with 403 (bypassing the UI). With a client that has `saldo_devedor > 0` (reuse/set up test data): register a payment less than the debt — confirm `saldo_devedor` decreases correctly and a `movimentos_credito` row appears in the extrato with the registering employee's name. Attempt to register a payment LARGER than the current debt — confirm rejection with "Valor maior que a dívida atual." and confirm `saldo_devedor` unchanged. Confirm the extrato correctly distinguishes "compra fiada" rows (from Task 1/3's testing) from "pagamento" rows, and shows "(cliente, online)" for any row with no `criado_por` (won't exist yet until Task 6/7, but verify the conditional logic is correct by reading the code).

- [ ] **Step 3: Commit**

```bash
git add clientes/detalhe.php
git commit -m "feat: add credit limit management and debt payment UI for staff"
```

---

### Task 5: Loja Online — pay with Linha de Crédito at checkout

**Files:**
- Modify: `loja/checkout.php`
- Create: `loja/ajax/finalizar_credito.php`

**Interfaces:**
- Consumes: `buscarCarrinhoDoCliente()` (Loja Online), `definirEntregaDaVenda()` (Task 2), `finalizarVenda()` accepting `'Linha de Crédito'` (Task 1)
- Produces: nothing new consumed by later tasks

- [ ] **Step 1: Add the credit option to `loja/checkout.php`**

Open `loja/checkout.php`. Find:

```php
$stmtCliente = $pdo->prepare('SELECT endereco FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();
```

Change to also fetch credit info:

```php
$stmtCliente = $pdo->prepare('SELECT endereco, limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
$temLimiteCredito = (float) $cliente['limite_credito'] > 0;
```

Find:

```html
        <button type="submit">Ir para pagamento</button>
    </form>
```

Change to:

```html
        <button type="submit" formaction="/loja/ajax/gerar_checkout.php">Pagar com Mercado Pago</button>

        <?php if ($temLimiteCredito): ?>
        <button type="submit" formaction="/loja/ajax/finalizar_credito.php" <?= $creditoDisponivel < (float) $venda['valor_total'] ? 'disabled' : '' ?>>
            Pagar com minha Linha de Crédito
            <?= $creditoDisponivel < (float) $venda['valor_total']
                ? ' (crédito insuficiente: disponível R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')'
                : ' (disponível: R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')' ?>
        </button>
        <?php endif; ?>
    </form>
```

Note: `$venda['valor_total']` here is the item-only subtotal (delivery hasn't been added yet at this point) — this comparison is a client-visible hint only, not the authoritative check. The real check happens server-side, after delivery is added, inside `finalizarVenda()`.

- [ ] **Step 2: Write `loja/ajax/finalizar_credito.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirClienteLogado();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /loja/checkout.php');
    exit;
}

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

if (!$id_venda) {
    header('Location: /loja/carrinho.php');
    exit;
}

$id_entrega = (int) ($_POST['id_entrega'] ?? 0);
$endereco = trim($_POST['endereco'] ?? '');

$stmtEntrega = $pdo->prepare('SELECT id_entrega, nome, tipo, custo FROM formas_entrega WHERE id_entrega = :id AND ativo = 1');
$stmtEntrega->execute([':id' => $id_entrega]);
$entrega = $stmtEntrega->fetch();

if (!$entrega) {
    header('Location: /loja/checkout.php?erro=' . urlencode('Selecione uma forma de entrega válida.'));
    exit;
}

if ($entrega['tipo'] !== 'retirada' && $endereco !== '') {
    $pdo->prepare('UPDATE clientes SET endereco = :endereco WHERE id_cliente = :id')
        ->execute([':endereco' => $endereco, ':id' => $id_cliente]);
}

$itens = $pdo->prepare(
    "SELECT id_item FROM itens_venda
     WHERE id_venda = :id AND NOT (id_produto_variacao IS NULL AND nome_produto = 'Entrega')"
);
$itens->execute([':id' => $id_venda]);
if (empty($itens->fetchAll())) {
    header('Location: /loja/carrinho.php');
    exit;
}

$valorTotalComEntrega = definirEntregaDaVenda($pdo, $id_venda, $entrega);

$resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Linha de Crédito', 'valor' => $valorTotalComEntrega]]);

if (!$resultado['success']) {
    header('Location: /loja/checkout.php?erro=' . urlencode($resultado['message']));
    exit;
}

header('Location: /loja/pedido_status.php?id_venda=' . $id_venda);
exit;
```

- [ ] **Step 3: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l loja/checkout.php` and `-l loja/ajax/finalizar_credito.php` — expect no syntax errors.

Set a real client's `limite_credito` high enough to cover a test order (reuse Task 1's approach). Log in as that client, add a product to the cart, visit `/loja/checkout.php` — confirm the "Pagar com minha Linha de Crédito" button shows the real available credit and is enabled. Pick a delivery method, click it — confirm: the venda becomes `'Pago'`, stock debited correctly, `clientes.saldo_devedor` increased by the exact total (items + delivery), a `movimentos_credito` row was created, and the browser lands on `/loja/pedido_status.php` showing the "Pagamento confirmado" message.

Now test insufficient credit: lower the client's available credit below the cart total (e.g. `UPDATE clientes SET saldo_devedor = limite_credito WHERE id_cliente = <id>`), add another item to a fresh cart, go to checkout — confirm the credit button is now disabled showing the insufficient-credit message, AND confirm a direct POST to `finalizar_credito.php` (bypassing the disabled button) is still rejected server-side with the cart/reservation left intact (check `itens_venda`/`estoque_reservado` unchanged).

Reset test data afterward.

- [ ] **Step 4: Commit**

```bash
git add loja/checkout.php loja/ajax/finalizar_credito.php
git commit -m "feat: add Linha de Crédito as a checkout payment method in the Loja Online"
```

---

### Task 6: "Meus débitos" page + Mercado Pago preference for debt payment

**Files:**
- Create: `loja/minha_divida.php`
- Create: `loja/ajax/gerar_checkout_divida.php`

**Interfaces:**
- Consumes: `exigirClienteLogado()`, `mpConfig()`/`mpChamarApi()` (`includes/mp_client.php`)
- Produces: `movimentos_credito` rows with `status='Pendente'`, `external_reference` format `'divida_' . id_movimento` — consumed by Task 7's webhook

- [ ] **Step 1: Write `loja/minha_divida.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$stmtCliente = $pdo->prepare('SELECT limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];

$stmtExtrato = $pdo->prepare(
    "SELECT tipo, status, valor, forma_pagamento, data_movimento
     FROM movimentos_credito
     WHERE id_cliente = :id
     ORDER BY data_movimento DESC"
);
$stmtExtrato->execute([':id' => $id_cliente]);
$extrato = $stmtExtrato->fetchAll();

$erro = $_GET['erro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Meus débitos</title></head>
<body>
    <p><a href="/loja/index.php">Voltar pra loja</a></p>
    <h1>Meus débitos</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <p>Limite de crédito: R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></p>
    <p>Saldo devedor: R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></p>
    <p>Crédito disponível: R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></p>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h2>Pagar dívida</h2>
    <form method="post" action="/loja/ajax/gerar_checkout_divida.php">
        <label>Valor a pagar
            <input type="text" name="valor" placeholder="0,00" value="<?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?>">
        </label>
        <button type="submit">Pagar com Mercado Pago</button>
    </form>
    <?php endif; ?>

    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p>Nenhum movimento ainda.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($extrato as $mov): ?>
        <li>
            <?= htmlspecialchars($mov['data_movimento']) ?> —
            <?= $mov['tipo'] === 'compra' ? 'Compra fiada' : 'Pagamento' ?>
            (<?= htmlspecialchars($mov['status']) ?>) —
            R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</body>
</html>
```

- [ ] **Step 2: Write `loja/ajax/gerar_checkout_divida.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirClienteLogado();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /loja/minha_divida.php');
    exit;
}

$id_cliente = (int) $_SESSION['id_cliente'];
$valor = (float) str_replace(',', '.', $_POST['valor'] ?? '0');

$stmtCliente = $pdo->prepare('SELECT saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$saldoDevedor = (float) $stmtCliente->fetchColumn();

if ($valor <= 0 || $valor > $saldoDevedor + 0.01) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Valor inválido.'));
    exit;
}

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Loja temporariamente indisponível para pagamento. Tente novamente mais tarde.'));
    exit;
}

$pdo->prepare(
    "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, forma_pagamento)
     VALUES (:ic, 'pagamento', 'Pendente', :valor, 'Mercado Pago')"
)->execute([':ic' => $id_cliente, ':valor' => $valor]);
$id_movimento = (int) $pdo->lastInsertId();

$application_fee = ceil($valor * 0.01 * 100) / 100;

$preference = [
    'items' => [[
        'title' => 'Pagamento de dívida - Brechó da Veve',
        'quantity' => 1,
        'currency_id' => 'BRL',
        'unit_price' => $valor,
    ]],
    'external_reference' => 'divida_' . $id_movimento,
    'notification_url' => 'https://brechodaveve.codernex.com.br/loja/api/notificacao_divida_mp.php',
    'back_urls' => [
        'success' => 'https://brechodaveve.codernex.com.br/loja/minha_divida.php',
        'pending' => 'https://brechodaveve.codernex.com.br/loja/minha_divida.php',
        'failure' => 'https://brechodaveve.codernex.com.br/loja/minha_divida.php',
    ],
    'auto_return' => 'approved',
    'marketplace_fee' => $application_fee,
    'sponsor_id' => 194420711,
    'excluded_payment_types' => [
        ['id' => 'ticket'],
    ],
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/checkout/preferences', $preference, $config['mp_access_token']);

    if ($resposta['http_code'] !== 201 || !isset($resposta['dados']['init_point'])) {
        header('Location: /loja/minha_divida.php?erro=' . urlencode('Erro ao gerar pagamento: ' . ($resposta['dados']['message'] ?? 'resposta inesperada do Mercado Pago')));
        exit;
    }

    header('Location: ' . $resposta['dados']['init_point']);
    exit;
} catch (Throwable $e) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Erro ao conectar com o Mercado Pago.'));
    exit;
}
```

- [ ] **Step 3: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l loja/minha_divida.php` and `-l loja/ajax/gerar_checkout_divida.php` — expect no syntax errors.

Set a test client's `saldo_devedor` to a real value (e.g. `50`). Log in as that client, visit `/loja/minha_divida.php` — confirm limite/saldo/disponível and the extrato render, and the "Pagar dívida" form pre-fills the full debt amount.

Test validation: POST a `valor` greater than `saldo_devedor` directly to `gerar_checkout_divida.php` — confirm rejection with "Valor inválido." and confirm NO `movimentos_credito` row was created (the validation must happen before the INSERT).

Test the "not connected" fallback (matches the established convention — no live Mercado Pago connection in local dev): with `config_pagamento.mp_access_token` empty, POST a valid `valor` — confirm it redirects with the friendly "temporariamente indisponível" message, WITHOUT crashing, and confirm a `movimentos_credito` row WAS still created with `status='Pendente'` (the insert happens before the MP call is attempted) — note this as a known residual: a failed MP-connection attempt currently leaves a stray Pendente row with no corresponding real preference; this is acceptable for now (visible in the staff extrato as "Pendente" for manual cleanup) but should be mentioned in your report as a minor finding for the final review to weigh.

Reset test data afterward.

- [ ] **Step 4: Commit**

```bash
git add loja/minha_divida.php loja/ajax/gerar_checkout_divida.php
git commit -m "feat: add client-facing debt view and Mercado Pago preference for debt payment"
```

---

### Task 7: Mercado Pago webhook for debt payment confirmation

**Files:**
- Create: `loja/api/notificacao_divida_mp.php`

**Interfaces:**
- Consumes: `mpValidarAssinaturaWebhook()`/`mpConfig()`/`mpChamarApi()` (`includes/mp_client.php`), `movimentos_credito` rows created by Task 6 (`external_reference` format `'divida_' . id_movimento`)
- Produces: nothing consumed by later tasks — this is the authoritative confirmation path for debt payments

- [ ] **Step 1: Write `loja/api/notificacao_divida_mp.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/mp_client.php';

// Sempre responde 200 pro Mercado Pago não ficar re-tentando indefinidamente —
// erros são só registrados em log, nunca retornados como HTTP de erro aqui.
http_response_code(200);
header('Content-Type: application/json');

$dataId = $_GET['data_id'] ?? ($_GET['id'] ?? null);
$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

if (!$dataId || !$xSignature || !defined('MP_WEBHOOK_SECRET')) {
    echo json_encode(['received' => true]);
    exit;
}

if (!mpValidarAssinaturaWebhook($xSignature, $xRequestId, strtolower((string) $dataId), MP_WEBHOOK_SECRET)) {
    error_log('Webhook divida MP: assinatura inválida para data.id=' . $dataId);
    echo json_encode(['received' => true]);
    exit;
}

try {
    $config = mpConfig($pdo);
    if (!$config || empty($config['mp_access_token'])) {
        echo json_encode(['received' => true]);
        exit;
    }

    $resposta = mpChamarApi('GET', 'https://api.mercadopago.com/v1/payments/' . $dataId, null, $config['mp_access_token']);
    $pagamento = $resposta['dados'] ?? [];
    $statusPagamento = $pagamento['status'] ?? null;

    $externalRef = $pagamento['external_reference'] ?? '';
    if (str_starts_with($externalRef, 'divida_')) {
        $id_movimento = (int) substr($externalRef, strlen('divida_'));

        $stmt = $pdo->prepare("SELECT id_cliente, valor, status FROM movimentos_credito WHERE id_movimento = :id AND tipo = 'pagamento'");
        $stmt->execute([':id' => $id_movimento]);
        $movimento = $stmt->fetch();

        if ($movimento && $movimento['status'] === 'Pendente') {
            if ($statusPagamento === 'approved') {
                $confirmou = $pdo->prepare(
                    "UPDATE movimentos_credito SET status = 'Confirmado', id_pagamento_mp = :idmp
                     WHERE id_movimento = :id AND status = 'Pendente'"
                );
                $confirmou->execute([':idmp' => $dataId, ':id' => $id_movimento]);

                if ($confirmou->rowCount() > 0) {
                    $pdo->prepare('UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor) WHERE id_cliente = :id')
                        ->execute([':valor' => $movimento['valor'], ':id' => $movimento['id_cliente']]);
                }
            } elseif (in_array($statusPagamento, ['rejected', 'cancelled'], true)) {
                $pdo->prepare(
                    "UPDATE movimentos_credito SET status = 'Cancelado', id_pagamento_mp = :idmp
                     WHERE id_movimento = :id AND status = 'Pendente'"
                )->execute([':idmp' => $dataId, ':id' => $id_movimento]);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Webhook divida MP erro: ' . $e->getMessage());
}

echo json_encode(['received' => true]);
```

- [ ] **Step 2: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l loja/api/notificacao_divida_mp.php` — expect no syntax errors.

Signature self-test (reuse the same technique already used for the PDV/Loja Online webhooks — synthetic secret, confirm `mpValidarAssinaturaWebhook()` returns `true`/`false`/`false` for valid/tampered/missing signatures — this function is unmodified, just reused, a quick spot-check is enough).

Real end-to-end test of the confirmation path: create a test `movimentos_credito` row directly (`tipo='pagamento'`, `status='Pendente'`, `valor=20`, a real `id_cliente` whose `saldo_devedor` you set to `50` for this test). Simulate the webhook's core logic being invoked with that row's id and an "approved" MP payment status (you can do this by calling the file's logic via a one-off script with a stubbed `mpChamarApi` response, or by directly running the SQL statements the file would run) — confirm: the movimento's `status` becomes `'Confirmado'`, `clientes.saldo_devedor` drops from `50` to `30`.

Test the double-delivery guard: run the exact same confirmation logic again against the now-`'Confirmado'` movimento — confirm the guarded `UPDATE` affects `0` rows and `saldo_devedor` does NOT decrease a second time.

Test the rejection path: create another test `'Pendente'` movimento, simulate a "rejected" MP payment status — confirm the movimento becomes `'Cancelado'` and `saldo_devedor` is untouched.

Full live webhook delivery from Mercado Pago's real servers requires the production domain and a connected account — defer that specific piece to production testing, same convention as every prior sub-project, and say so clearly in your report.

Reset test data afterward.

- [ ] **Step 3: Commit**

```bash
git add loja/api/notificacao_divida_mp.php
git commit -m "feat: add Mercado Pago webhook for debt payment confirmation"
```

---

## Self-Review Notes

- **Spec coverage:** schema + atomic balance guard (Task 1), PDV credit sale (Task 1 + 3), admin limit management + staff debt-payment registration (Task 4), Loja Online credit checkout (Task 2 + 5), client debt view + online payment (Task 6), webhook confirmation (Task 7). All spec sections have a task. The shared `definirEntregaDaVenda()` extraction (Task 2) wasn't explicitly named in the spec but directly serves the spec's Loja Online credit-checkout requirement without duplicating already-reviewed checkout logic — a deliberate, justified refactor, not scope creep.
- **No interest/due-date logic anywhere** — confirmed no task introduces date comparisons, rate calculations, or overdue logic, matching the spec's explicit "sem juros" constraint.
- **Cross-task interface consistency:** `finalizarVenda()`'s signature is unchanged across every task that calls it (Task 3 via the existing PDV flow, Task 5's new `finalizar_credito.php`) — only the accepted `forma` values grew. `definirEntregaDaVenda(PDO $pdo, int $id_venda, array $entrega): float` (Task 2) is called identically in Task 5 with the same `$entrega` array shape (`id_entrega`/`nome`/`custo`) as it's defined. The `movimentos_credito` schema (Task 1) is consumed identically by Task 4's extrato query and Task 6/7's debt-payment flow. `saldo_devedor`'s two guarded-`UPDATE` forms (increase in `finalizarVenda()`, decrease in `clientes/detalhe.php` and the webhook) all use the `GREATEST(0, ...)` floor on decrease and the `<= limite_credito` guard on increase, consistently.
- **The "Linha de Crédito exige cliente vinculado" rule is enforced at the single choke point** (`finalizarVenda()`, Task 1) rather than separately in the PDV UI and the Loja Online endpoint — the PDV's `caixa/pagamento.php` (Task 3) only disables the option as a UX hint when no client is linked, but the authoritative rejection happens inside `finalizarVenda()` itself, so there's no way to bypass the check from either caller.
