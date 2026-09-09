# Loja Online / App do Cliente — Sistema Veronica — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Loja Online sub-project: a public catalog, client account/login, a cart with a real atomic stock reservation (exclusive across both the online store and the physical PDV — no two people can ever hold the same stock unit at once), and checkout via Mercado Pago Checkout Pro covering every payment method enabled on the store's account.

**Architecture:** Same procedural PHP 8.5 style as the Fundação and PDV sub-projects. Reuses the Fundação's `produtos`/`categorias`/`formas_entrega` tables and the PDV's `vendas`/`itens_venda`/`venda_pagamentos` tables and shared `finalizarVenda()` (`includes/caixa.php`) — extended, not duplicated, so online and in-person sales stay in one place. The one new cross-cutting mechanism is an atomic `estoque_reservado` counter on `produto_variacoes`, guarded by a single `UPDATE ... WHERE` per reservation/release, which makes the "only one person at a time" rule airtight without needing application-level locks — and which the PDV's own `adicionar_item.php` (already in production) is taught to respect too, so an online hold blocks an in-person sale of the same unit and vice versa.

**Tech Stack:** PHP 8.5, MySQL 8 (InnoDB, utf8mb4), PDO, native PHP sessions (separate namespace from staff sessions), Mercado Pago REST API (Checkout Pro preferences + the same webhook signature verification built in the PDV sub-project).

**Spec:** [docs/superpowers/specs/2026-09-09-loja-online-design.md](../specs/2026-09-09-loja-online-design.md)

## Global Constraints

- No automated tests — every task's verification step is a manual procedure (curl + direct DB queries against a real running server) to run and observe, matching the Fundação and PDV sub-projects' convention.
- Money fields are `DECIMAL(10,2)`; every place a Brazilian comma-decimal string enters PHP from a form, normalize with `str_replace(',', '.', $value)` **before** the `(float)` cast — this exact bug has already been found and fixed three times across this project.
- **The reservation rule is absolute, not probabilistic**: at most one active hold on any unit of stock at any time, enforced by a single atomic `UPDATE produto_variacoes SET estoque_reservado = estoque_reservado + :qtd WHERE id_produto_variacao = :id AND (estoque - estoque_reservado) >= :qtd` — if it affects 0 rows, the reservation failed, full stop, nothing else happens. This rule applies across **both** channels: the online cart (this sub-project) and the PDV's `adicionar_item.php` (already in production, gets a one-line fix in Task 2).
- `finalizarVenda()` (`includes/caixa.php`, PDV sub-project) is the single choke point for turning a hold into an actual sale (debiting `estoque` and releasing the matching `estoque_reservado`) — reused unmodified in its structure, with one small, `GREATEST`-guarded addition (Task 2) that is safe for both channels (a PDV sale never touched `estoque_reservado`, so the guard is a no-op there).
- Client sessions use `$_SESSION['id_cliente']` — a completely separate namespace from staff sessions (`$_SESSION['id_usuario']`), via a new `includes/auth_cliente.php` parallel to the existing `includes/auth.php`.
- WhatsApp is the client login identifier (matches the Fundação's existing uniqueness rule on `clientes.whatsapp`), normalized identically to `clientes/novo.php`: digits only, DDI `55` prepended when the result is 10 or 11 digits.
- `marketplace_fee` (1%, `ceil($valor * 0.01 * 100) / 100`) and `sponsor_id: 194420711` apply to the Mercado Pago Checkout Pro preference this sub-project creates, same as every other Mercado Pago charge in this project.
- **The exact customer-facing message when a payment can't be fulfilled due to a stock conflict is `"Item não liberado. Demora no pagamento."`** — confirmed wording, not a placeholder to improve on.
- All PDO/money/escaping conventions from the Fundação and PDV sub-projects continue to apply (bound parameters everywhere, `htmlspecialchars()` on all dynamic HTML output, exceptions propagate rather than being echoed raw, `.textContent`/`.value`/DOM element creation for any dynamic text in JS — never `.innerHTML`/`insertAdjacentHTML` with string concatenation).

---

## File Structure

```
sql/schema_loja.sql
includes/auth_cliente.php
includes/loja.php
loja/cadastro.php
loja/logout.php
loja/index.php
loja/produto.php
loja/ajax/adicionar_item.php
loja/ajax/remover_item.php
loja/carrinho.php
loja/checkout.php
loja/ajax/gerar_checkout.php
loja/pedido_status.php
loja/api/notificacao_mp.php
includes/caixa.php          (modify — reservation release in finalizarVenda())
caixa/ajax/adicionar_item.php  (modify — respect online reservations)
```

---

### Task 1: Database schema + shared loja helpers

**Files:**
- Create: `sql/schema_loja.sql`
- Create: `includes/loja.php`

**Interfaces:**
- Consumes: `$pdo` (from `conecta_bd.php`), `vendas`/`itens_venda`/`produto_variacoes`/`clientes`/`config_loja`/`formas_entrega` (Fundação/PDV, extended here)
- Produces: `liberarReservasExpiradas(PDO $pdo): void`, `devolverReservaDaVenda(PDO $pdo, int $id_venda): void`, `buscarCarrinhoDoCliente(PDO $pdo, int $id_cliente): ?int` — consumed by every later task in this plan

- [ ] **Step 1: Write the SQL migration**

Create `sql/schema_loja.sql`:

```sql
ALTER TABLE vendas
    MODIFY COLUMN id_caixa INT NULL,
    MODIFY COLUMN id_usuario INT NULL,
    MODIFY COLUMN status ENUM('Reservado','Pago','Cancelado') NOT NULL DEFAULT 'Reservado',
    ADD COLUMN origem ENUM('pdv','loja') NOT NULL DEFAULT 'pdv',
    ADD COLUMN id_entrega INT NULL,
    ADD FOREIGN KEY (id_entrega) REFERENCES formas_entrega(id_entrega);

ALTER TABLE clientes ADD COLUMN senha_hash VARCHAR(255) NULL;

ALTER TABLE produto_variacoes ADD COLUMN estoque_reservado INT NOT NULL DEFAULT 0;

ALTER TABLE config_loja ADD COLUMN prazo_reserva_minutos INT NOT NULL DEFAULT 15;
```

- [ ] **Step 2: Run the migration**

Run: `mysql -u root sistema_veronica < sql/schema_loja.sql`
Verify: `mysql -u root sistema_veronica -e "DESCRIBE vendas"` — confirm `origem`, `id_entrega` present and `id_caixa`/`id_usuario` show `YES` under `Null`. `mysql -u root sistema_veronica -e "DESCRIBE clientes"` — confirm `senha_hash`. `mysql -u root sistema_veronica -e "DESCRIBE produto_variacoes"` — confirm `estoque_reservado`. `mysql -u root sistema_veronica -e "SELECT * FROM config_loja"` — confirm `prazo_reserva_minutos = 15`. Also confirm existing PDV data survived: `mysql -u root sistema_veronica -e "SELECT id_venda, status, origem FROM vendas"` — every pre-existing row should show `origem = 'pdv'`.

- [ ] **Step 3: Write `includes/loja.php`**

```php
<?php

/**
 * Libera reservas de carrinho da loja online que passaram do prazo — devolve
 * a reserva de cada item e marca a venda como cancelada. Chamada no início
 * de toda página/endpoint da loja que lê estoque ou carrinho, em vez de
 * depender de um cron job (a liberação só acontece quando alguém acessa o
 * sistema, mas isso é suficiente pra este projeto).
 */
function liberarReservasExpiradas(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT v.id_venda
         FROM vendas v
         JOIN config_loja cl ON cl.id_config = 1
         WHERE v.status = 'Reservado' AND v.origem = 'loja'
           AND v.data_venda < DATE_SUB(NOW(), INTERVAL cl.prazo_reserva_minutos MINUTE)"
    );
    $stmt->execute();
    $vendasExpiradas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($vendasExpiradas as $id_venda) {
        devolverReservaDaVenda($pdo, (int) $id_venda);
        $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id")
            ->execute([':id' => $id_venda]);
    }
}

/**
 * Devolve a reserva (estoque_reservado) de todos os itens de uma venda —
 * reaproveitada pela expiração automática, pela remoção explícita de um
 * item do carrinho, e por uma falha de finalização vinda do webhook.
 */
function devolverReservaDaVenda(PDO $pdo, int $id_venda): void
{
    $stmt = $pdo->prepare('SELECT id_produto_variacao, quantidade FROM itens_venda WHERE id_venda = :id');
    $stmt->execute([':id' => $id_venda]);
    foreach ($stmt->fetchAll() as $item) {
        if ($item['id_produto_variacao'] === null) {
            continue;
        }
        $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
            ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);
    }
}

/**
 * Busca a venda "Reservado" em andamento deste cliente na loja online, se
 * houver.
 */
function buscarCarrinhoDoCliente(PDO $pdo, int $id_cliente): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id_venda FROM vendas WHERE id_cliente = :ic AND origem = 'loja' AND status = 'Reservado' ORDER BY data_venda DESC LIMIT 1"
    );
    $stmt->execute([':ic' => $id_cliente]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}
```

- [ ] **Step 4: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l includes/loja.php` — expect no syntax errors. Smoke test:
```
C:\wamp64\bin\php\php8.5.0\php.exe -r "require 'conecta_bd.php'; require 'includes/loja.php'; liberarReservasExpiradas($pdo); var_dump(buscarCarrinhoDoCliente($pdo, 999999));"
```
Expected: no error, `NULL` (no cart for a nonexistent client id).

- [ ] **Step 5: Commit**

```bash
git add sql/schema_loja.sql includes/loja.php
git commit -m "feat: add Loja Online database schema and shared helpers"
```

---

### Task 2: Shared reservation-release fix + PDV cross-channel enforcement

**Files:**
- Modify: `includes/caixa.php` (the stock-debit loop inside `finalizarVenda()`)
- Modify: `caixa/ajax/adicionar_item.php`

**Interfaces:**
- Consumes: `produto_variacoes.estoque_reservado` (Task 1)
- Produces: `finalizarVenda()` now also releases the matching reservation when a sale (either channel) is finalized; PDV's add-item flow now refuses to sell a unit that's actively held by an online cart — consumed by every later task that calls `finalizarVenda()` or exercises the PDV cart

- [ ] **Step 1: Update the stock-debit loop in `finalizarVenda()`**

Open `includes/caixa.php`. Find the loop that debits stock — it currently looks like this (the loop variable is `$id_pv`, not `$id` — match it exactly):

```php
foreach ($quantidadePorVariacao as $id_pv => $quantidadeTotal) {
    $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque - :qtd WHERE id_produto_variacao = :id')
        ->execute([':qtd' => $quantidadeTotal, ':id' => $id_pv]);
}
```

Change the `UPDATE` to also release the reservation, guarded by `GREATEST(0, ...)` so it's safe for PDV sales (which never touch `estoque_reservado`, so it stays `0` and the `GREATEST` is a no-op):

```php
foreach ($quantidadePorVariacao as $id_pv => $quantidadeTotal) {
    $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque - :qtd, estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
        ->execute([':qtd' => $quantidadeTotal, ':id' => $id_pv]);
}
```

Do not change anything else in this function — the transaction structure, the `FOR UPDATE` locks, the payment-sufficiency check, the `ksort()` ordering, all stay exactly as they are.

- [ ] **Step 2: Make `caixa/ajax/adicionar_item.php` respect online reservations**

Open `caixa/ajax/adicionar_item.php`. Find the SELECT that reads stock — it currently looks like this:

```php
$stmt = $pdo->prepare(
    'SELECT pv.estoque, COALESCE(pv.preco, p.preco_base) AS preco, p.nome AS nome_produto,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao
     FROM produto_variacoes pv
     JOIN produtos p ON p.id_produto = pv.id_produto
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE pv.id_produto_variacao = :id
     GROUP BY pv.estoque, pv.preco, p.preco_base, p.nome'
);
```

Add `pv.estoque_reservado` to the `SELECT` list and to the `GROUP BY` list:

```php
$stmt = $pdo->prepare(
    'SELECT pv.estoque, pv.estoque_reservado, COALESCE(pv.preco, p.preco_base) AS preco, p.nome AS nome_produto,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao
     FROM produto_variacoes pv
     JOIN produtos p ON p.id_produto = pv.id_produto
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE pv.id_produto_variacao = :id
     GROUP BY pv.estoque, pv.estoque_reservado, pv.preco, p.preco_base, p.nome'
);
```

Then find the stock-sufficiency check, currently:

```php
if ($produto['estoque'] < $quantidade) {
    echo json_encode(['success' => false, 'message' => 'Estoque insuficiente (disponível: ' . $produto['estoque'] . ').']);
    exit;
}
```

Change it to compare against the *available* quantity (physical stock minus whatever's actively held, by either channel):

```php
$disponivel = $produto['estoque'] - $produto['estoque_reservado'];
if ($disponivel < $quantidade) {
    echo json_encode(['success' => false, 'message' => 'Estoque insuficiente (disponível: ' . $disponivel . ').']);
    exit;
}
```

- [ ] **Step 3: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l includes/caixa.php` and `-l caixa/ajax/adicionar_item.php` — expect no syntax errors.

Regression-check `finalizarVenda()` still works normally for a PDV sale: use the existing PDV flow (open caixa if needed, add an item, pay with Dinheiro, finalize) — confirm `estoque` decrements correctly and `estoque_reservado` stays `0` throughout (query `produto_variacoes` directly before/after).

Test the new cross-channel guard directly against the DB (no online cart UI exists yet — Task 6 builds it): manually set a fake reservation on a test combination (`UPDATE produto_variacoes SET estoque_reservado = estoque WHERE id_produto_variacao = <id>` — i.e. "fully reserved", zero available), then try to add that same combination via `caixa/ajax/adicionar_item.php` (logged in as Admin, quantidade=1) — confirm it's rejected with "Estoque insuficiente (disponível: 0)." Reset the test data afterward (`UPDATE produto_variacoes SET estoque_reservado = 0 WHERE id_produto_variacao = <id>`).

- [ ] **Step 4: Commit**

```bash
git add includes/caixa.php caixa/ajax/adicionar_item.php
git commit -m "feat: release stock reservation on sale finalization, enforce it in the PDV too"
```

---

### Task 3: Client login (WhatsApp-based, ativar/cadastro/login)

**Files:**
- Create: `includes/auth_cliente.php`
- Create: `loja/cadastro.php`
- Create: `loja/logout.php`

**Interfaces:**
- Consumes: `$pdo`, `clientes` (Fundação, extended in Task 1)
- Produces: `exigirClienteLogado(): void`, `$_SESSION['id_cliente']`/`$_SESSION['nome_cliente']` — consumed by every later task in this plan

- [ ] **Step 1: Write `includes/auth_cliente.php`**

```php
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirClienteLogado(): void
{
    if (empty($_SESSION['id_cliente'])) {
        header('Location: /loja/cadastro.php');
        exit;
    }
}
```

- [ ] **Step 2: Write `loja/cadastro.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';

if (!empty($_SESSION['id_cliente'])) {
    header('Location: /loja/index.php');
    exit;
}

$erro = '';
$etapa = 'whatsapp';
$whatsappNormalizado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'verificar_whatsapp') {
        $whatsappNormalizado = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
        if (strlen($whatsappNormalizado) === 10 || strlen($whatsappNormalizado) === 11) {
            $whatsappNormalizado = '55' . $whatsappNormalizado;
        }

        if (strlen($whatsappNormalizado) < 12) {
            $erro = 'Informe um WhatsApp válido (com DDD).';
            $etapa = 'whatsapp';
        } else {
            $stmt = $pdo->prepare('SELECT id_cliente, senha_hash FROM clientes WHERE whatsapp = :w');
            $stmt->execute([':w' => $whatsappNormalizado]);
            $clienteExistente = $stmt->fetch();

            if (!$clienteExistente) {
                $etapa = 'cadastro';
            } elseif (empty($clienteExistente['senha_hash'])) {
                $etapa = 'ativar';
            } else {
                $etapa = 'login';
            }
        }
    } elseif ($acao === 'cadastro') {
        $whatsappNormalizado = $_POST['whatsapp'] ?? '';
        $nome = trim($_POST['nome'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($nome === '' || strlen($senha) < 6) {
            $erro = 'Informe seu nome e uma senha com pelo menos 6 caracteres.';
            $etapa = 'cadastro';
        } else {
            try {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO clientes (nome, whatsapp, senha_hash) VALUES (:nome, :whatsapp, :senha)');
                $stmt->execute([':nome' => $nome, ':whatsapp' => $whatsappNormalizado, ':senha' => $hash]);
                $_SESSION['id_cliente'] = (int) $pdo->lastInsertId();
                $_SESSION['nome_cliente'] = $nome;
                header('Location: /loja/index.php');
                exit;
            } catch (PDOException $e) {
                $erro = 'Esse WhatsApp já tem cadastro. Tente entrar em vez de se cadastrar.';
                $etapa = 'whatsapp';
            }
        }
    } elseif ($acao === 'ativar') {
        $whatsappNormalizado = $_POST['whatsapp'] ?? '';
        $senha = $_POST['senha'] ?? '';

        if (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
            $etapa = 'ativar';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE clientes SET senha_hash = :senha WHERE whatsapp = :whatsapp')
                ->execute([':senha' => $hash, ':whatsapp' => $whatsappNormalizado]);

            $stmtC = $pdo->prepare('SELECT id_cliente, nome FROM clientes WHERE whatsapp = :whatsapp');
            $stmtC->execute([':whatsapp' => $whatsappNormalizado]);
            $cliente = $stmtC->fetch();

            $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
            $_SESSION['nome_cliente'] = $cliente['nome'];
            header('Location: /loja/index.php');
            exit;
        }
    } elseif ($acao === 'login') {
        $whatsappNormalizado = $_POST['whatsapp'] ?? '';
        $senha = $_POST['senha'] ?? '';

        $stmt = $pdo->prepare('SELECT id_cliente, nome, senha_hash FROM clientes WHERE whatsapp = :whatsapp');
        $stmt->execute([':whatsapp' => $whatsappNormalizado]);
        $cliente = $stmt->fetch();

        if ($cliente && password_verify($senha, $cliente['senha_hash'])) {
            $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
            $_SESSION['nome_cliente'] = $cliente['nome'];
            header('Location: /loja/index.php');
            exit;
        }

        $erro = 'WhatsApp ou senha inválidos.';
        $etapa = 'login';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Entrar</title></head>
<body>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <?php if ($etapa === 'whatsapp'): ?>
    <form method="post">
        <input type="hidden" name="acao" value="verificar_whatsapp">
        <label>WhatsApp (com DDD)<br><input type="text" name="whatsapp" required placeholder="11987654321"></label><br>
        <button type="submit">Continuar</button>
    </form>
    <?php elseif ($etapa === 'cadastro'): ?>
    <h2>Complete seu cadastro</h2>
    <form method="post">
        <input type="hidden" name="acao" value="cadastro">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Nome<br><input type="text" name="nome" required></label><br>
        <label>Crie uma senha<br><input type="password" name="senha" required minlength="6"></label><br>
        <button type="submit">Cadastrar</button>
    </form>
    <?php elseif ($etapa === 'ativar'): ?>
    <h2>Ativar minha conta</h2>
    <p>Encontramos seu cadastro. Crie uma senha pra acessar a loja online.</p>
    <form method="post">
        <input type="hidden" name="acao" value="ativar">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Crie uma senha<br><input type="password" name="senha" required minlength="6"></label><br>
        <button type="submit">Ativar</button>
    </form>
    <?php elseif ($etapa === 'login'): ?>
    <h2>Entrar</h2>
    <form method="post">
        <input type="hidden" name="acao" value="login">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Senha<br><input type="password" name="senha" required></label><br>
        <button type="submit">Entrar</button>
    </form>
    <?php endif; ?>
</body>
</html>
```

- [ ] **Step 3: Write `loja/logout.php`**

```php
<?php
require_once __DIR__ . '/../includes/auth_cliente.php';
unset($_SESSION['id_cliente'], $_SESSION['nome_cliente']);
header('Location: /loja/index.php');
exit;
```

- [ ] **Step 4: Verify manually**

Run `php -l` on all three files. Via curl with a cookie jar (`curl -c cookies_cliente.txt -b cookies_cliente.txt ...`):
1. POST `acao=verificar_whatsapp` with a brand-new WhatsApp (e.g. `11999998888`) — confirm the response HTML shows the "cadastro" form.
2. POST `acao=cadastro` with `whatsapp` (the normalized `5511999998888`), `nome`, `senha` — confirm redirect to `/loja/index.php` (404 is fine, Task 4 builds it) and that `$_SESSION['id_cliente']` is set (verifiable by then hitting any page that requires it once Task 4 exists, or by querying `clientes` directly to confirm the row and `senha_hash` were created).
3. Try `acao=cadastro` again with the SAME WhatsApp — confirm the friendly "já tem cadastro" error, not a raw DB error.
4. Fresh cookie jar, POST `acao=verificar_whatsapp` with that same WhatsApp again — confirm the response now shows the "login" form (not "cadastro", since it already has a `senha_hash`).
5. POST `acao=login` with the right whatsapp+senha — confirm redirect and session set. With the wrong senha — confirm "WhatsApp ou senha inválidos."
6. Create a client via the *Fundação* admin flow (`/clientes/novo.php`, logged in as staff) with a fresh WhatsApp and no password — then, in a client cookie jar, POST `acao=verificar_whatsapp` with that same WhatsApp — confirm it shows the "ativar" form (not "cadastro"), and that `acao=ativar` correctly sets a password on that *existing* row rather than creating a duplicate.
7. Hit `/loja/logout.php` — confirm `$_SESSION['id_cliente']` is gone afterward.

- [ ] **Step 5: Commit**

```bash
git add includes/auth_cliente.php loja/cadastro.php loja/logout.php
git commit -m "feat: add client login (WhatsApp-based, cadastro/ativar/login)"
```

---

### Task 4: Public catalog

**Files:**
- Create: `loja/index.php`

**Interfaces:**
- Consumes: `liberarReservasExpiradas()` (Task 1), `produtos`/`categorias`/`produto_variacoes`/`produto_fotos` (Fundação)
- Produces: nothing new consumed by later tasks (links to Task 5's `loja/produto.php`)

- [ ] **Step 1: Write `loja/index.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

liberarReservasExpiradas($pdo);

$id_categoria = (int) ($_GET['categoria'] ?? 0);

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();

$sql = "SELECT DISTINCT p.id_produto, p.nome, p.preco_base,
               (SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = p.id_produto ORDER BY ordem LIMIT 1) AS foto
        FROM produtos p
        JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE p.ativo = 1 AND (pv.estoque - pv.estoque_reservado) > 0";
$params = [];
if ($id_categoria > 0) {
    $sql .= ' AND p.id_categoria = :ic';
    $params[':ic'] = $id_categoria;
}
$sql .= ' ORDER BY p.nome';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produtos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Loja</title></head>
<body>
    <h1>Loja</h1>
    <p>
        <?php if (!empty($_SESSION['id_cliente'])): ?>
            Olá, <?= htmlspecialchars($_SESSION['nome_cliente']) ?> —
            <a href="/loja/carrinho.php">Carrinho</a> | <a href="/loja/logout.php">Sair</a>
        <?php else: ?>
            <a href="/loja/cadastro.php">Entrar / Cadastrar</a>
        <?php endif; ?>
    </p>

    <nav>
        <a href="/loja/index.php">Todas as categorias</a>
        <?php foreach ($categorias as $c): ?>
            | <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>"><?= htmlspecialchars($c['nome']) ?></a>
        <?php endforeach; ?>
    </nav>

    <div>
        <?php foreach ($produtos as $p): ?>
        <div>
            <a href="/loja/produto.php?id=<?= $p['id_produto'] ?>">
                <?php if ($p['foto']): ?><img src="/<?= htmlspecialchars($p['foto']) ?>" width="150"><?php endif; ?>
                <div><?= htmlspecialchars($p['nome']) ?></div>
                <div>R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Run `php -l`. Visit `/loja/index.php` — confirm it lists active products with available stock (a product whose every combination is fully reserved/out of stock should NOT appear; use the test data from earlier tasks to confirm). Click a category filter link — confirm the list narrows correctly. Confirm the header shows "Entrar / Cadastrar" when not logged in, and the client's name + Carrinho/Sair links when logged in (use the cookie jar from Task 3's test).

- [ ] **Step 3: Commit**

```bash
git add loja/index.php
git commit -m "feat: add public product catalog"
```

---

### Task 5: Product detail page

**Files:**
- Create: `loja/produto.php`

**Interfaces:**
- Consumes: `$pdo`, `produtos`/`produto_variacoes`/`produto_fotos`/`variacao_valores` (Fundação)
- Produces: nothing new consumed by later tasks (its "Adicionar ao carrinho" button calls Task 6's `loja/ajax/adicionar_item.php`)

- [ ] **Step 1: Write `loja/produto.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

liberarReservasExpiradas($pdo);

$id_produto = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT p.*, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.id_produto = :id AND p.ativo = 1');
$stmt->execute([':id' => $id_produto]);
$produto = $stmt->fetch();

if (!$produto) {
    http_response_code(404);
    echo 'Produto não encontrado.';
    exit;
}

$fotos = $pdo->prepare('SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = :id ORDER BY ordem');
$fotos->execute([':id' => $id_produto]);
$listaFotos = $fotos->fetchAll(PDO::FETCH_COLUMN);

$combinacoes = $pdo->prepare(
    'SELECT pv.id_produto_variacao, COALESCE(pv.preco, p.preco_base) AS preco,
            (pv.estoque - pv.estoque_reservado) AS disponivel,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao
     FROM produto_variacoes pv
     JOIN produtos p ON p.id_produto = pv.id_produto
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE pv.id_produto = :id
     GROUP BY pv.id_produto_variacao, pv.preco, p.preco_base, pv.estoque, pv.estoque_reservado
     HAVING disponivel > 0
     ORDER BY descricao_combinacao'
);
$combinacoes->execute([':id' => $id_produto]);
$listaCombinacoes = $combinacoes->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($produto['nome']) ?></title></head>
<body>
    <p><a href="/loja/index.php">Voltar</a></p>
    <h1><?= htmlspecialchars($produto['nome']) ?></h1>
    <p><?= htmlspecialchars($produto['descricao'] ?? '') ?></p>
    <p>Categoria: <?= htmlspecialchars($produto['categoria']) ?></p>

    <div>
        <?php foreach ($listaFotos as $foto): ?>
            <img src="/<?= htmlspecialchars($foto) ?>" width="150">
        <?php endforeach; ?>
    </div>

    <?php if (empty($_SESSION['id_cliente'])): ?>
        <p><a href="/loja/cadastro.php">Entre ou cadastre-se</a> pra comprar.</p>
    <?php elseif (empty($listaCombinacoes)): ?>
        <p>Sem estoque disponível no momento.</p>
    <?php else: ?>
    <form id="form-adicionar">
        <label>Opção
            <select name="id_produto_variacao" id="id_produto_variacao">
                <?php foreach ($listaCombinacoes as $c): ?>
                <option value="<?= $c['id_produto_variacao'] ?>">
                    <?= htmlspecialchars($c['descricao_combinacao'] ?: 'Padrão') ?> — R$ <?= number_format($c['preco'], 2, ',', '.') ?> (<?= (int) $c['disponivel'] ?> disponível)
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit">Adicionar ao carrinho</button>
    </form>
    <p id="mensagem-adicionar"></p>
    <script>
    document.getElementById('form-adicionar').addEventListener('submit', function (e) {
        e.preventDefault();
        const idProdutoVariacao = document.getElementById('id_produto_variacao').value;
        fetch('/loja/ajax/adicionar_item.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_produto_variacao=' + idProdutoVariacao + '&quantidade=1'
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window.location.href = '/loja/carrinho.php';
            } else {
                document.getElementById('mensagem-adicionar').textContent = data.message;
            }
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Run `php -l`. Visit `/loja/produto.php?id=<id de um produto com estoque>` — confirm photos, description, and the combination dropdown (showing the right "disponível" count per combination) render correctly. Visit with a nonexistent id — confirm 404. While NOT logged in (fresh cookie jar) — confirm it shows the "Entre ou cadastre-se" link instead of the add-to-cart form.

- [ ] **Step 3: Commit**

```bash
git add loja/produto.php
git commit -m "feat: add product detail page with variation picker"
```

---

### Task 6: Add/remove cart item (atomic reservation)

**Files:**
- Create: `loja/ajax/adicionar_item.php`
- Create: `loja/ajax/remover_item.php`

**Interfaces:**
- Consumes: `exigirClienteLogado()` (Task 3), `buscarCarrinhoDoCliente()` (Task 1), `recalcularTotalVenda()` (PDV, `includes/caixa.php`)
- Produces: `itens_venda` rows for `origem='loja'` sales, `produto_variacoes.estoque_reservado` correctly incremented/decremented — consumed by Tasks 7-10

- [ ] **Step 1: Write `loja/ajax/adicionar_item.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirClienteLogado();
header('Content-Type: application/json');

liberarReservasExpiradas($pdo);

$id_produto_variacao = (int) ($_POST['id_produto_variacao'] ?? 0);
$quantidade = max(1, (int) ($_POST['quantidade'] ?? 1));
$id_cliente = (int) $_SESSION['id_cliente'];

$pdo->beginTransaction();
try {
    $reservou = $pdo->prepare(
        'UPDATE produto_variacoes SET estoque_reservado = estoque_reservado + :qtd
         WHERE id_produto_variacao = :id AND (estoque - estoque_reservado) >= :qtd2'
    );
    $reservou->execute([':qtd' => $quantidade, ':id' => $id_produto_variacao, ':qtd2' => $quantidade]);

    if ($reservou->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Esse item não está mais disponível nessa quantidade.']);
        exit;
    }

    $stmtProduto = $pdo->prepare(
        'SELECT p.nome AS nome_produto, COALESCE(pv.preco, p.preco_base) AS preco,
                GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao
         FROM produto_variacoes pv
         JOIN produtos p ON p.id_produto = pv.id_produto
         LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
         LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
         WHERE pv.id_produto_variacao = :id
         GROUP BY p.nome, pv.preco, p.preco_base'
    );
    $stmtProduto->execute([':id' => $id_produto_variacao]);
    $produto = $stmtProduto->fetch();

    $id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);
    if (!$id_venda) {
        $pdo->prepare("INSERT INTO vendas (id_cliente, origem, status) VALUES (:ic, 'loja', 'Reservado')")
            ->execute([':ic' => $id_cliente]);
        $id_venda = (int) $pdo->lastInsertId();
    }

    $preco_unit = (float) $produto['preco'];
    $subtotal = $preco_unit * $quantidade;

    $pdo->prepare(
        'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
         VALUES (:iv, :nome, :desc, :ipv, :qtd, :preco, :subtotal)'
    )->execute([
        ':iv' => $id_venda,
        ':nome' => $produto['nome_produto'],
        ':desc' => $produto['descricao_combinacao'] ?: null,
        ':ipv' => $id_produto_variacao,
        ':qtd' => $quantidade,
        ':preco' => $preco_unit,
        ':subtotal' => $subtotal,
    ]);

    recalcularTotalVenda($pdo, $id_venda);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro ao adicionar ao carrinho.']);
}
```

- [ ] **Step 2: Write `loja/ajax/remover_item.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirClienteLogado();
header('Content-Type: application/json');

$id_item = (int) ($_POST['id_item'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT iv.id_item, iv.id_venda, iv.id_produto_variacao, iv.quantidade
     FROM itens_venda iv
     JOIN vendas v ON v.id_venda = iv.id_venda
     WHERE iv.id_item = :id AND v.id_cliente = :ic AND v.origem = 'loja' AND v.status = 'Reservado'"
);
$stmt->execute([':id' => $id_item, ':ic' => $id_cliente]);
$item = $stmt->fetch();

if (!$item) {
    echo json_encode(['success' => false, 'message' => 'Item não encontrado.']);
    exit;
}

if ($item['id_produto_variacao'] !== null) {
    $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
        ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);
}

$pdo->prepare('DELETE FROM itens_venda WHERE id_item = :id')->execute([':id' => $item['id_item']]);

recalcularTotalVenda($pdo, (int) $item['id_venda']);

echo json_encode(['success' => true]);
```

- [ ] **Step 3: Verify manually**

Run `php -l` on both. Using the client cookie jar from Task 3's testing: add a product combination to the cart — confirm `itens_venda` has the row and `produto_variacoes.estoque_reservado` incremented by the right amount (query directly). Add it again with the SAME combination in a way that would exceed availability (e.g. `quantidade` larger than what's left) — confirm rejection with "não está mais disponível", and confirm `estoque_reservado` did NOT change from the rejected attempt.

**Test the exclusivity rule directly**: with the item still reserved by this client, log in as a SECOND distinct client (create one via `/loja/cadastro.php` if needed) and try to add the exact same combination with a quantity that would exceed what's left after the first client's hold — confirm it's rejected. Then remove the item via `remover_item.php` (as the first client) — confirm `estoque_reservado` goes back down, and confirm the second client can now successfully add it.

- [ ] **Step 4: Commit**

```bash
git add loja/ajax/adicionar_item.php loja/ajax/remover_item.php
git commit -m "feat: add cart item add/remove with atomic stock reservation"
```

---

### Task 7: Cart page

**Files:**
- Create: `loja/carrinho.php`

**Interfaces:**
- Consumes: `exigirClienteLogado()`, `liberarReservasExpiradas()`, `buscarCarrinhoDoCliente()` (Tasks 1, 3), `itens_venda`/`vendas` (Task 6)
- Produces: nothing new consumed by later tasks (links to Task 8's `loja/checkout.php`)

- [ ] **Step 1: Write `loja/carrinho.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

liberarReservasExpiradas($pdo);

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

$itens = [];
$total = 0;
$dataVenda = null;
if ($id_venda) {
    $stmt = $pdo->prepare('SELECT id_item, nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
    $stmt->execute([':id' => $id_venda]);
    $itens = $stmt->fetchAll();

    $stmtV = $pdo->prepare('SELECT valor_total, data_venda FROM vendas WHERE id_venda = :id');
    $stmtV->execute([':id' => $id_venda]);
    $venda = $stmtV->fetch();
    $total = (float) $venda['valor_total'];
    $dataVenda = $venda['data_venda'];
}

$config = $pdo->query('SELECT prazo_reserva_minutos FROM config_loja WHERE id_config = 1')->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Carrinho</title></head>
<body>
    <p><a href="/loja/index.php">Continuar comprando</a></p>
    <h1>Carrinho</h1>

    <?php if (empty($itens)): ?>
    <p>Seu carrinho está vazio.</p>
    <?php else: ?>
    <?php if ($dataVenda): ?>
    <p id="contagem"></p>
    <script>
    const expiraEm = new Date('<?= date('c', strtotime($dataVenda) + ((int) $config['prazo_reserva_minutos'] * 60)) ?>').getTime();
    function atualizarContagem() {
        const restante = Math.max(0, Math.floor((expiraEm - Date.now()) / 1000));
        const min = Math.floor(restante / 60);
        const seg = restante % 60;
        document.getElementById('contagem').textContent = 'Tempo pra pagar: ' + min + ':' + String(seg).padStart(2, '0');
        if (restante <= 0) {
            window.location.reload();
        }
    }
    atualizarContagem();
    setInterval(atualizarContagem, 1000);
    </script>
    <?php endif; ?>

    <ul>
        <?php foreach ($itens as $item): ?>
        <li>
            <?= (int) $item['quantidade'] ?>x <?= htmlspecialchars($item['nome_produto']) ?>
            <?= $item['descricao_combinacao'] ? '(' . htmlspecialchars($item['descricao_combinacao']) . ')' : '' ?>
            — R$ <?= number_format($item['subtotal'], 2, ',', '.') ?>
            <button type="button" data-id-item="<?= $item['id_item'] ?>" class="btn-remover">remover</button>
        </li>
        <?php endforeach; ?>
    </ul>
    <p>Total: R$ <?= number_format($total, 2, ',', '.') ?></p>
    <p><a href="/loja/checkout.php">Ir para o checkout</a></p>
    <script>
    document.querySelectorAll('.btn-remover').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fetch('/loja/ajax/remover_item.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_item=' + btn.dataset.idItem
            }).then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { alert(data.message); }
            });
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Run `php -l`. With items in the cart (from Task 6's testing) — visit `/loja/carrinho.php`, confirm the items, total, and a live countdown timer render correctly. Click "remover" on an item — confirm it disappears and the total updates. Empty the cart entirely — confirm "Seu carrinho está vazio." shows. Note: no `.innerHTML` with dynamic content anywhere in this file's JS — the remove handler only reads `btn.dataset.idItem` (a numeric id already escaped server-side into the `data-id-item` attribute) and never renders text via `innerHTML`.

- [ ] **Step 3: Commit**

```bash
git add loja/carrinho.php
git commit -m "feat: add cart page with countdown timer and item removal"
```

---

### Task 8: Checkout + Mercado Pago Checkout Pro preference

**Files:**
- Create: `loja/checkout.php`
- Create: `loja/ajax/gerar_checkout.php`

**Interfaces:**
- Consumes: `buscarCarrinhoDoCliente()` (Task 1), `mpConfig()`/`mpChamarApi()` (PDV, `includes/mp_client.php`), `formas_entrega` (Fundação)
- Produces: `vendas.id_entrega`/`vendas.valor_total` (updated to include delivery cost), `vendas.id_pagamento_mp` (preference id) — consumed by Task 9/10

- [ ] **Step 1: Write `loja/checkout.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

liberarReservasExpiradas($pdo);

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

if (!$id_venda) {
    header('Location: /loja/carrinho.php');
    exit;
}

$stmtV = $pdo->prepare('SELECT valor_total FROM vendas WHERE id_venda = :id');
$stmtV->execute([':id' => $id_venda]);
$venda = $stmtV->fetch();

$stmtCliente = $pdo->prepare('SELECT endereco FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$formasEntrega = $pdo->query('SELECT id_entrega, nome, tipo, prazo_dias, custo FROM formas_entrega WHERE ativo = 1 ORDER BY fixa DESC, nome')->fetchAll();

$erro = $_GET['erro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Checkout</title></head>
<body>
    <h1>Checkout</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <p>Total dos itens: R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></p>

    <form method="post" action="/loja/ajax/gerar_checkout.php">
        <label>Forma de entrega
            <select name="id_entrega" id="id_entrega">
                <?php foreach ($formasEntrega as $f): ?>
                <option value="<?= $f['id_entrega'] ?>" data-tipo="<?= htmlspecialchars($f['tipo']) ?>">
                    <?= htmlspecialchars($f['nome']) ?>
                    <?= $f['prazo_dias'] !== null ? '(' . (int) $f['prazo_dias'] . ' dias)' : '' ?>
                    — R$ <?= number_format($f['custo'], 2, ',', '.') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label><br>
        <div id="campo-endereco">
            <label>Endereço de entrega<br>
                <textarea name="endereco"><?= htmlspecialchars($cliente['endereco'] ?? '') ?></textarea>
            </label>
        </div>
        <button type="submit">Ir para pagamento</button>
    </form>

<script>
function atualizarCampoEndereco() {
    const select = document.getElementById('id_entrega');
    const opcao = select.options[select.selectedIndex];
    const tipo = opcao ? opcao.dataset.tipo : null;
    document.getElementById('campo-endereco').style.display = tipo === 'retirada' ? 'none' : '';
}
document.getElementById('id_entrega').addEventListener('change', atualizarCampoEndereco);
atualizarCampoEndereco();
</script>
</body>
</html>
```

- [ ] **Step 2: Write `loja/ajax/gerar_checkout.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirClienteLogado();

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

$itens = $pdo->prepare('SELECT nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

if (empty($listaItens)) {
    header('Location: /loja/carrinho.php');
    exit;
}

$subtotalItens = array_sum(array_column($listaItens, 'subtotal'));
$valorTotalComEntrega = $subtotalItens + (float) $entrega['custo'];

$pdo->prepare('UPDATE vendas SET id_entrega = :ie, valor_total = :total WHERE id_venda = :iv')
    ->execute([':ie' => $id_entrega, ':total' => $valorTotalComEntrega, ':iv' => $id_venda]);

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    header('Location: /loja/checkout.php?erro=' . urlencode('Loja temporariamente indisponível para pagamento. Tente novamente mais tarde.'));
    exit;
}

$itensMp = [];
foreach ($listaItens as $item) {
    $itensMp[] = [
        'title' => $item['nome_produto'] . ($item['descricao_combinacao'] ? ' (' . $item['descricao_combinacao'] . ')' : ''),
        'quantity' => (int) $item['quantidade'],
        'currency_id' => 'BRL',
        'unit_price' => (float) $item['preco_unit'],
    ];
}
if ((float) $entrega['custo'] > 0) {
    $itensMp[] = ['title' => 'Entrega: ' . $entrega['nome'], 'quantity' => 1, 'currency_id' => 'BRL', 'unit_price' => (float) $entrega['custo']];
}

$application_fee = ceil($valorTotalComEntrega * 0.01 * 100) / 100;

$preference = [
    'items' => $itensMp,
    'external_reference' => 'loja_' . $id_venda,
    'notification_url' => 'https://brechodaveve.codernex.com.br/loja/api/notificacao_mp.php',
    'back_urls' => [
        'success' => 'https://brechodaveve.codernex.com.br/loja/pedido_status.php?id_venda=' . $id_venda,
        'pending' => 'https://brechodaveve.codernex.com.br/loja/pedido_status.php?id_venda=' . $id_venda,
        'failure' => 'https://brechodaveve.codernex.com.br/loja/pedido_status.php?id_venda=' . $id_venda,
    ],
    'auto_return' => 'approved',
    'marketplace_fee' => $application_fee,
    'sponsor_id' => 194420711,
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/checkout/preferences', $preference, $config['mp_access_token']);

    if ($resposta['http_code'] !== 201 || !isset($resposta['dados']['init_point'])) {
        header('Location: /loja/checkout.php?erro=' . urlencode('Erro ao gerar pagamento: ' . ($resposta['dados']['message'] ?? 'resposta inesperada do Mercado Pago')));
        exit;
    }

    $pdo->prepare('UPDATE vendas SET id_pagamento_mp = :id WHERE id_venda = :iv')
        ->execute([':id' => $resposta['dados']['id'], ':iv' => $id_venda]);

    header('Location: ' . $resposta['dados']['init_point']);
    exit;
} catch (Throwable $e) {
    header('Location: /loja/checkout.php?erro=' . urlencode('Erro ao conectar com o Mercado Pago.'));
    exit;
}
```

- [ ] **Step 3: Verify manually**

Run `php -l` on both files. Visit `/loja/checkout.php` with items in the cart — confirm the delivery dropdown lists `formas_entrega` correctly, and that choosing a non-"retirada" option shows the address field while "retirada" hides it (JS behavior, verify by reading the code and toggling manually in a real browser if available, or note as a lighter check since no headless browser is available in this environment).

**No live Mercado Pago connection exists yet for this store in the local dev environment** (matches PDV's established convention) — verify the "not connected" path: with `config_pagamento.mp_access_token` empty, POST to `gerar_checkout.php` with a valid `id_entrega` — confirm it redirects to `/loja/checkout.php?erro=...` with the "temporariamente indisponível" message, WITHOUT crashing, and confirm `vendas.id_entrega`/`valor_total` were still updated correctly (query directly) even though the MP call never happened — this proves the delivery-cost-rollup logic is correct independent of the MP integration. If a fake `mp_access_token` is available for structural testing (same technique used in the PDV sub-project — a fake token still reaches Mercado Pago's real API and gets a real error back), use it to confirm the preference payload reaches Mercado Pago and gets a real (non-transport) error response, proving the request shape is well-formed; clean up the fake token afterward.

- [ ] **Step 4: Commit**

```bash
git add loja/checkout.php loja/ajax/gerar_checkout.php
git commit -m "feat: add checkout with delivery selection and Mercado Pago preference"
```

---

### Task 9: Order status page

**Files:**
- Create: `loja/pedido_status.php`

**Interfaces:**
- Consumes: `exigirClienteLogado()`, `vendas` (Task 8)
- Produces: nothing new consumed by later tasks — this is where Mercado Pago's `back_urls` (Task 8) land the customer, and it's the page that must show the exact `"Item não liberado. Demora no pagamento."` message when Task 10's webhook cancels a sale

- [ ] **Step 1: Write `loja/pedido_status.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_venda = :id AND id_cliente = :ic AND origem = 'loja'");
$stmt->execute([':id' => $id_venda, ':ic' => $id_cliente]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Pedido não encontrado.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Status do pedido</title></head>
<body>
    <h1>Pedido #<?= $id_venda ?></h1>
    <?php if ($venda['status'] === 'Pago'): ?>
        <p style="color:green;">Pagamento confirmado! Seu pedido já está sendo preparado.</p>
    <?php elseif ($venda['status'] === 'Cancelado'): ?>
        <p style="color:red;">Item não liberado. Demora no pagamento.</p>
        <p>Se você já pagou, a loja entrará em contato pra resolver (reembolso ou reposição).</p>
    <?php else: ?>
        <p>Aguardando confirmação do pagamento...</p>
        <script>setTimeout(function () { window.location.reload(); }, 5000);</script>
    <?php endif; ?>
    <p><a href="/loja/index.php">Voltar pra loja</a></p>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Run `php -l`. With a venda in `'Reservado'` state — visit `/loja/pedido_status.php?id_venda=<id>`, confirm "Aguardando confirmação..." shows with the auto-reload script present. Manually set that same venda to `'Pago'` (`UPDATE vendas SET status = 'Pago' WHERE id_venda = <id>`), reload — confirm the green success message. Manually set it to `'Cancelado'`, reload — confirm the exact red message **"Item não liberado. Demora no pagamento."** appears verbatim. Try visiting a venda id belonging to a DIFFERENT client (or with `origem != 'loja'`) — confirm 404, not someone else's order details.

- [ ] **Step 3: Commit**

```bash
git add loja/pedido_status.php
git commit -m "feat: add order status page"
```

---

### Task 10: Mercado Pago webhook

**Files:**
- Create: `loja/api/notificacao_mp.php`

**Interfaces:**
- Consumes: `mpValidarAssinaturaWebhook()`/`mpConfig()`/`mpChamarApi()` (PDV, `includes/mp_client.php`), `finalizarVenda()` (PDV, `includes/caixa.php`), `devolverReservaDaVenda()` (Task 1)
- Produces: nothing consumed by later tasks — this is the authoritative confirmation path for online orders, converging with the customer's own polling on `pedido_status.php`

- [ ] **Step 1: Write `loja/api/notificacao_mp.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/loja.php';
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
    error_log('Webhook loja MP: assinatura inválida para data.id=' . $dataId);
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

    if (($pagamento['status'] ?? null) === 'approved') {
        $externalRef = $pagamento['external_reference'] ?? '';
        if (str_starts_with($externalRef, 'loja_')) {
            $id_venda = (int) substr($externalRef, strlen('loja_'));
            $stmt = $pdo->prepare("SELECT status FROM vendas WHERE id_venda = :id AND origem = 'loja'");
            $stmt->execute([':id' => $id_venda]);
            $venda = $stmt->fetch();

            if ($venda && $venda['status'] === 'Reservado') {
                $valorPago = (float) ($pagamento['transaction_amount'] ?? 0);
                $resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Mercado Pago', 'valor' => $valorPago]], (string) $dataId);

                if (!$resultado['success']) {
                    error_log('Webhook loja MP: falha ao finalizar venda ' . $id_venda . ': ' . $resultado['message']);
                    // A reserva ainda está segurando o estoque (finalizarVenda() não
                    // debita nada quando rejeita) — precisa devolver antes de cancelar,
                    // senão o item fica preso, reservado pra sempre, sem ninguém poder
                    // comprá-lo de novo.
                    devolverReservaDaVenda($pdo, $id_venda);
                    $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id AND status = 'Reservado'")
                        ->execute([':id' => $id_venda]);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Webhook loja MP erro: ' . $e->getMessage());
}

echo json_encode(['received' => true]);
```

- [ ] **Step 2: Verify manually**

Run `php -l`. Reuse the same signature self-test technique from the PDV sub-project (synthetic secret, `true`/`false`/`false` sequence) to confirm `mpValidarAssinaturaWebhook()` still behaves correctly (it's unmodified, just reused — a quick spot-check is enough).

**Test the "insufficient stock at confirmation" path directly**, since this is the one behavior unique to this task and the most important to get right: create a test venda (`origem='loja'`, `status='Reservado'`) with one `itens_venda` row referencing a real `id_produto_variacao`, with `quantidade` set higher than that combination's current real `estoque` (simulating "reservation succeeded earlier, but something changed the physical stock since then"), and make sure `estoque_reservado` for that combination reflects a matching hold (set it manually to simulate the state honestly). Call `finalizarVenda()` directly via a one-off script the same way this webhook would, confirm it returns `success: false`. Then run the webhook's own failure-handling logic (the `devolverReservaDaVenda()` + status-to-`Cancelado` update) against that same test venda and confirm: `estoque_reservado` drops back down correctly, `vendas.status` becomes `'Cancelado'`, and visiting `/loja/pedido_status.php?id_venda=<that id>` (Task 9) now shows the exact "Item não liberado. Demora no pagamento." message.

Full live webhook delivery from Mercado Pago's real servers requires the production domain and a connected account — defer that specific piece to production testing, same convention as the PDV sub-project, and say so clearly in the report.

- [ ] **Step 3: Commit**

```bash
git add loja/api/notificacao_mp.php
git commit -m "feat: add Mercado Pago webhook for online orders"
```

---

## Self-Review Notes

- **Spec coverage:** shared vendas/itens_venda/venda_pagamentos reuse (Task 1), client WhatsApp login/ativação (Task 3), catalog (Task 4), product detail (Task 5), atomic reservation exclusive across both channels (Task 2 + Task 6), cart with countdown (Task 7), delivery selection + Checkout Pro with marketplace_fee/sponsor_id (Task 8), the exact "Item não liberado. Demora no pagamento." message (Task 9), webhook confirmation converging on `finalizarVenda()` (Task 10). All spec sections have a task.
- **The reservation-release-on-failure gap is fixed at design time, not left for review to catch.** Task 10's webhook explicitly calls `devolverReservaDaVenda()` before marking a failed-finalization venda `'Cancelado'` — without this, a stock unit held by a sale that fails `finalizarVenda()`'s sufficiency check would stay reserved forever (marking `'Cancelado'` alone doesn't free it, since `liberarReservasExpiradas()` only ever looks at `'Reservado'` rows). This mirrors a class of bug the PDV sub-project's final review had to catch after the fact (the Pix-approved-but-insufficient stall) — fixed here proactively instead.
- **Cross-task interface consistency check:** `buscarCarrinhoDoCliente()` (Task 1) used identically in Tasks 6, 7, 8 with the same `(PDO $pdo, int $id_cliente): ?int` signature. `id_produto_variacao` as the reservation/stock key used consistently in Task 2 (PDV touch-up), Task 6 (add/remove), Task 10 (webhook failure path). `external_reference` format (`'loja_' . $id_venda`) produced in Task 8 and parsed identically in Task 10 (`str_starts_with` + `substr`), matching the PDV sub-project's identical `'venda_' . $id_venda` convention for its own channel.
- **The `GREATEST(0, ...)` guard in `finalizarVenda()` (Task 2) is the one change to already-shipped PDV code this plan requires**, and it's deliberately structured to be a no-op for every PDV sale (which never sets `estoque_reservado` above 0) — Task 2's own verification step includes a PDV regression check for exactly this reason.
