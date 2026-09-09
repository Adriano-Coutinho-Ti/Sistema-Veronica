# Fundação — Sistema Veronica — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation of the Sistema Veronica brechó management system: login/users, client registry, fully configurable product categories/variations with per-combination stock, photo upload with GD-based resizing, store branding config, and delivery methods config.

**Architecture:** Plain procedural PHP 8.5 (page-per-file, folder-per-module), mirroring the `D:\Projetos clientes - CODERNEX\sys01` reference project's conventions (PDO + `conecta_bd.php`, credentials one level above the repo, native PHP sessions, GD for image processing). No framework, no `id_empresa` (single-tenant), no automated test suite — every task ends with a manual verification procedure instead of a unit test, matching how `sys01` is validated.

**Tech Stack:** PHP 8.5, MySQL 8 (InnoDB, utf8mb4), PDO, native PHP sessions, GD extension (bundled with PHP, no Composer dependency).

**Spec:** [docs/superpowers/specs/2026-09-08-fundacao-design.md](../specs/2026-09-08-fundacao-design.md)

## Global Constraints

- No `id_empresa` / multi-tenant anywhere — this system serves a single store.
- No automated tests (PHPUnit etc.) — every task's verification step is a manual procedure to run and observe.
- Money fields are `DECIMAL(10,2)`.
- Passwords hashed with `password_hash(..., PASSWORD_DEFAULT)` / verified with `password_verify`.
- DB credentials live in `config_credenciais.php` **one level above the repo root** (matches `sys01`'s `require_once __DIR__ . '/../config_credenciais.php'` pattern) — this file is never committed.
- Sale records (built in the next sub-project) will **not** hold a foreign key to `produtos`/`produto_variacoes` — noted here because it affects how `produtos` deletion is allowed to behave (always physically deletable).
- Photos are always stored on disk under `assets/img/produtos/{id_produto}/`, never in the database — only their path is stored.
- All PDO connections use `PDO::ATTR_ERRMODE_EXCEPTION`; page code must not swallow `PDOException` and echo raw DB error text to the browser (an information-leak pattern present in `sys01`'s `conecta_bd.php` that we deliberately do not copy) — let exceptions propagate to PHP's own error log.

---

## File Structure

```
config_credenciais.php          (OUTSIDE the repo — D:\Projetos clientes - CODERNEX\config_credenciais.php)
conecta_bd.php
.gitignore
sql/schema.sql
includes/auth.php
login.php
sair.php
clientes/novo.php
clientes/lista.php
clientes/detalhe.php
usuarios/novo.php
usuarios/lista.php
produtos/categorias.php
produtos/variacoes.php
produtos/novo.php
produtos/lista.php
produtos/editar.php
produtos/ajax/upload_foto.php
produtos/ajax/deletar_foto.php
produtos/ajax/deletar_produto.php
produtos/ajax/gerar_combinacoes.php
produtos/ajax/listar_variacoes_categoria.php
config_sistema/aparencia.php
config_sistema/entrega.php
assets/img/produtos/.gitkeep
```

---

### Task 1: Database schema + DB connection

**Files:**
- Create: `sql/schema.sql`
- Create: `conecta_bd.php`
- Create: `.gitignore`
- Create: `assets/img/produtos/.gitkeep`

**Interfaces:**
- Consumes: nothing (first task)
- Produces: `$pdo` (PDO instance, `PDO::FETCH_ASSOC` default, exceptions on error) available to any file that does `require_once __DIR__ . '/../conecta_bd.php'` (adjust `../` depth per folder)

- [ ] **Step 1: Create the credentials file outside the repo**

Create `D:\Projetos clientes - CODERNEX\config_credenciais.php` (note: **outside** `Sistema-Veronica`, one level up) with your local MySQL credentials:

```php
<?php
$host = '127.0.0.1';
$dbname = 'sistema_veronica';
$username = 'root';
$password = '';
```

- [ ] **Step 2: Write the SQL schema**

Create `sql/schema.sql`:

```sql
CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('Admin','Funcionario') NOT NULL DEFAULT 'Funcionario',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clientes (
    id_cliente INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(160) NULL,
    endereco VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categorias (
    id_categoria INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE variacoes (
    id_variacao INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE variacao_valores (
    id_valor INT AUTO_INCREMENT PRIMARY KEY,
    id_variacao INT NOT NULL,
    valor VARCHAR(100) NOT NULL,
    FOREIGN KEY (id_variacao) REFERENCES variacoes(id_variacao) ON DELETE CASCADE,
    UNIQUE KEY uniq_valor_por_variacao (id_variacao, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categoria_variacoes (
    id_categoria INT NOT NULL,
    id_variacao INT NOT NULL,
    PRIMARY KEY (id_categoria, id_variacao),
    FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria) ON DELETE CASCADE,
    FOREIGN KEY (id_variacao) REFERENCES variacoes(id_variacao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produtos (
    id_produto INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT NULL,
    id_categoria INT NOT NULL,
    condicao ENUM('novo','usado') NOT NULL DEFAULT 'usado',
    preco_base DECIMAL(10,2) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produto_variacoes (
    id_produto_variacao INT AUTO_INCREMENT PRIMARY KEY,
    id_produto INT NOT NULL,
    preco DECIMAL(10,2) NULL,
    estoque INT NOT NULL DEFAULT 0,
    FOREIGN KEY (id_produto) REFERENCES produtos(id_produto) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produto_variacao_valores (
    id_produto_variacao INT NOT NULL,
    id_valor INT NOT NULL,
    PRIMARY KEY (id_produto_variacao, id_valor),
    FOREIGN KEY (id_produto_variacao) REFERENCES produto_variacoes(id_produto_variacao) ON DELETE CASCADE,
    FOREIGN KEY (id_valor) REFERENCES variacao_valores(id_valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produto_fotos (
    id_foto INT AUTO_INCREMENT PRIMARY KEY,
    id_produto INT NOT NULL,
    ordem TINYINT NOT NULL,
    caminho_arquivo VARCHAR(255) NOT NULL,
    FOREIGN KEY (id_produto) REFERENCES produtos(id_produto) ON DELETE CASCADE,
    UNIQUE KEY uniq_ordem_por_produto (id_produto, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE config_loja (
    id_config INT PRIMARY KEY,
    nome_loja VARCHAR(150) NOT NULL DEFAULT 'Minha Loja',
    logo_arquivo VARCHAR(255) NULL,
    cor_primaria CHAR(7) NOT NULL DEFAULT '#8B5CF6',
    cor_secundaria CHAR(7) NOT NULL DEFAULT '#F472B6'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO config_loja (id_config) VALUES (1);

CREATE TABLE formas_entrega (
    id_entrega INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    tipo ENUM('retirada','entrega') NOT NULL,
    prazo_dias INT NULL,
    custo DECIMAL(10,2) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    fixa TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO formas_entrega (nome, tipo, prazo_dias, custo, ativo, fixa)
VALUES ('Retirar na loja', 'retirada', 5, 0, 1, 1);
```

- [ ] **Step 3: Create the database and run the schema**

Run:
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS sistema_veronica CHARACTER SET utf8mb4"
mysql -u root sistema_veronica < sql/schema.sql
```
Expected: no errors. Verify with `mysql -u root sistema_veronica -e "SHOW TABLES"` — should list all 10 tables.

- [ ] **Step 4: Write `conecta_bd.php`**

```php
<?php
require_once __DIR__ . '/../config_credenciais.php';

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
];

$pdo = new PDO($dsn, $username, $password, $options);
```

- [ ] **Step 5: Create `.gitignore`**

```
/assets/img/produtos/*
!/assets/img/produtos/.gitkeep
```

Create empty file `assets/img/produtos/.gitkeep` so the folder exists in a fresh checkout.

- [ ] **Step 6: Verify the connection manually**

Run: `php -r "require 'conecta_bd.php'; var_dump($pdo->query('SELECT 1')->fetchColumn());"`
Expected: `int(1)` printed, no exception.

- [ ] **Step 7: Commit**

```bash
git add sql/schema.sql conecta_bd.php .gitignore assets/img/produtos/.gitkeep
git commit -m "feat: add database schema and PDO connection"
```

---

### Task 2: Session guard + login/logout

**Files:**
- Create: `includes/auth.php`
- Create: `login.php`
- Create: `sair.php`

**Interfaces:**
- Consumes: `$pdo` (Task 1)
- Produces: `exigirLogin()`, `exigirAdmin()` (both `void`, redirect/exit if unauthorized) — every protected page in later tasks calls one of these at the top, after requiring `includes/auth.php`. Session keys set on login: `$_SESSION['id_usuario']` (int), `$_SESSION['nome']` (string), `$_SESSION['perfil']` (`'Admin'|'Funcionario'`).

- [ ] **Step 1: Write `includes/auth.php`**

```php
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirLogin(): void
{
    if (empty($_SESSION['id_usuario'])) {
        header('Location: /login.php');
        exit;
    }
}

function exigirAdmin(): void
{
    exigirLogin();
    if (($_SESSION['perfil'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }
}
```

- [ ] **Step 2: Write `login.php`**

```php
<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/auth.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $pdo->prepare('SELECT id_usuario, nome, senha_hash, perfil FROM usuarios WHERE email = :email AND ativo = 1');
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
        $_SESSION['id_usuario'] = $usuario['id_usuario'];
        $_SESSION['nome'] = $usuario['nome'];
        $_SESSION['perfil'] = $usuario['perfil'];
        header('Location: /produtos/lista.php');
        exit;
    }

    $erro = 'E-mail ou senha inválidos.';
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Entrar — Sistema Veronica</title>
</head>
<body>
    <h1>Entrar</h1>
    <?php if ($erro): ?>
        <p style="color:red;"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>E-mail<br><input type="email" name="email" required></label><br>
        <label>Senha<br><input type="password" name="senha" required></label><br>
        <button type="submit">Entrar</button>
    </form>
</body>
</html>
```

- [ ] **Step 3: Write `sair.php`**

```php
<?php
require_once __DIR__ . '/includes/auth.php';
session_destroy();
header('Location: /login.php');
exit;
```

- [ ] **Step 4: Seed an admin user and verify login manually**

Run:
```bash
php -r "require 'conecta_bd.php'; $h = password_hash('troque-esta-senha', PASSWORD_DEFAULT); $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?,?,?,?)')->execute(['Dona da Loja','dona@example.com',$h,'Admin']);"
```
Start a local PHP server: `php -S localhost:8000`. Open `http://localhost:8000/login.php`, log in with `dona@example.com` / `troque-esta-senha`.
Expected: redirected (404 is fine — `produtos/lista.php` doesn't exist yet, but the redirect happening and `$_SESSION` being set, checkable by visiting any page that calls `exigirLogin()`, confirms the login worked). Try wrong password — expect "E-mail ou senha inválidos." Visit `/sair.php` — expect redirect to `/login.php`, and a protected page should now redirect back to login.

- [ ] **Step 5: Commit**

```bash
git add includes/auth.php login.php sair.php
git commit -m "feat: add login, logout, and session guard"
```

---

### Task 3: User management (Admin creates Funcionário accounts)

**Files:**
- Create: `usuarios/novo.php`
- Create: `usuarios/lista.php`

**Interfaces:**
- Consumes: `exigirAdmin()` (Task 2), `$pdo` (Task 1)
- Produces: nothing consumed by later tasks

- [ ] **Step 1: Write `usuarios/novo.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $perfil = $_POST['perfil'] === 'Admin' ? 'Admin' : 'Funcionario';

    if ($nome === '' || $email === '' || strlen($senha) < 6) {
        $erro = 'Preencha nome, e-mail e uma senha com pelo menos 6 caracteres.';
    } else {
        $existe = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = :email');
        $existe->execute([':email' => $email]);
        if ($existe->fetch()) {
            $erro = 'Já existe um usuário com esse e-mail.';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (:nome, :email, :hash, :perfil)');
            $stmt->execute([':nome' => $nome, ':email' => $email, ':hash' => $hash, ':perfil' => $perfil]);
            header('Location: /usuarios/lista.php?criado=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Novo usuário</title></head>
<body>
    <h1>Novo usuário</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <label>Nome<br><input type="text" name="nome" required></label><br>
        <label>E-mail<br><input type="email" name="email" required></label><br>
        <label>Senha<br><input type="password" name="senha" required minlength="6"></label><br>
        <label>Perfil<br>
            <select name="perfil">
                <option value="Funcionario">Funcionário</option>
                <option value="Admin">Admin</option>
            </select>
        </label><br>
        <button type="submit">Salvar</button>
    </form>
    <p><a href="/usuarios/lista.php">Ver usuários</a></p>
</body>
</html>
```

- [ ] **Step 2: Write `usuarios/lista.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$usuarios = $pdo->query('SELECT id_usuario, nome, email, perfil, ativo FROM usuarios ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Usuários</title></head>
<body>
    <h1>Usuários</h1>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Usuário criado com sucesso.</p><?php endif; ?>
    <p><a href="/usuarios/novo.php">+ Novo usuário</a></p>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th></tr>
        <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['nome']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['perfil']) ?></td>
            <td><?= $u['ativo'] ? 'Sim' : 'Não' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
```

- [ ] **Step 3: Verify manually**

With the dev server running and logged in as the admin seeded in Task 2, visit `/usuarios/novo.php`, create a Funcionário account, confirm redirect to `/usuarios/lista.php?criado=1` and that the new row appears. Try creating a second user with the same e-mail — expect "Já existe um usuário com esse e-mail." Log out, log in as the new Funcionário, then visit `/usuarios/novo.php` directly — expect HTTP 403 "Acesso restrito ao administrador."

- [ ] **Step 4: Commit**

```bash
git add usuarios/novo.php usuarios/lista.php
git commit -m "feat: add user management for Admin"
```

---

### Task 4: Clientes CRUD (WhatsApp obrigatório e único)

**Files:**
- Create: `clientes/novo.php`
- Create: `clientes/lista.php`
- Create: `clientes/detalhe.php`

**Interfaces:**
- Consumes: `exigirLogin()` (Task 2), `$pdo` (Task 1)
- Produces: `clientes` table rows consumed by later sub-projects (PDV, Linha de Crédito) — `id_cliente`, `nome`, `whatsapp` (digits only, e.g. `5511987654321`)

- [ ] **Step 1: Write `clientes/novo.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    $email = trim($_POST['email'] ?? '') ?: null;
    $endereco = trim($_POST['endereco'] ?? '') ?: null;

    if ($nome === '' || strlen($whatsapp) < 10) {
        $erro = 'Informe nome e um WhatsApp válido (com DDD).';
    } else {
        $existe = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :whatsapp');
        $existe->execute([':whatsapp' => $whatsapp]);
        if ($existe->fetch()) {
            $erro = 'Já existe um cliente cadastrado com esse WhatsApp.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO clientes (nome, whatsapp, email, endereco) VALUES (:nome, :whatsapp, :email, :endereco)');
            $stmt->execute([':nome' => $nome, ':whatsapp' => $whatsapp, ':email' => $email, ':endereco' => $endereco]);
            header('Location: /clientes/lista.php?criado=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Novo cliente</title></head>
<body>
    <h1>Novo cliente</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <label>Nome<br><input type="text" name="nome" required></label><br>
        <label>WhatsApp (com DDD)<br><input type="text" name="whatsapp" required placeholder="11987654321"></label><br>
        <label>E-mail<br><input type="email" name="email"></label><br>
        <label>Endereço<br><input type="text" name="endereco"></label><br>
        <button type="submit">Salvar</button>
    </form>
    <p><a href="/clientes/lista.php">Ver clientes</a></p>
</body>
</html>
```

- [ ] **Step 2: Write `clientes/lista.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$clientes = $pdo->query('SELECT id_cliente, nome, whatsapp, email FROM clientes ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Clientes</title></head>
<body>
    <h1>Clientes</h1>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Cliente cadastrado com sucesso.</p><?php endif; ?>
    <p><a href="/clientes/novo.php">+ Novo cliente</a></p>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th></th></tr>
        <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['nome']) ?></td>
            <td><?= htmlspecialchars($c['whatsapp']) ?></td>
            <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
            <td><a href="/clientes/detalhe.php?id=<?= $c['id_cliente'] ?>">ver</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
```

- [ ] **Step 3: Write `clientes/detalhe.php`**

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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($cliente['nome']) ?></title></head>
<body>
    <h1><?= htmlspecialchars($cliente['nome']) ?></h1>
    <p>WhatsApp: <?= htmlspecialchars($cliente['whatsapp']) ?></p>
    <p>E-mail: <?= htmlspecialchars($cliente['email'] ?? '—') ?></p>
    <p>Endereço: <?= htmlspecialchars($cliente['endereco'] ?? '—') ?></p>
    <p><a href="/clientes/lista.php">Voltar</a></p>
</body>
</html>
```

- [ ] **Step 4: Verify manually**

Logged in, visit `/clientes/novo.php`, create a client with WhatsApp `11987654321`. Confirm it appears in `/clientes/lista.php`. Try registering a second client with the same WhatsApp — expect "Já existe um cliente cadastrado com esse WhatsApp." Click "ver" and confirm `/clientes/detalhe.php` shows the right data.

- [ ] **Step 5: Commit**

```bash
git add clientes/novo.php clientes/lista.php clientes/detalhe.php
git commit -m "feat: add clientes CRUD with unique WhatsApp"
```

---

### Task 5: Categorias CRUD

**Files:**
- Create: `produtos/categorias.php`

**Interfaces:**
- Consumes: `exigirLogin()` (Task 2), `$pdo` (Task 1)
- Produces: `categorias` rows (`id_categoria`, `nome`) consumed by Task 6 (`categoria_variacoes`) and Task 7 (product creation)

- [ ] **Step 1: Write `produtos/categorias.php`** (list + create + delete-if-unused, all on one page)

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') {
        $erro = 'Informe o nome da categoria.';
    } else {
        $existe = $pdo->prepare('SELECT id_categoria FROM categorias WHERE nome = :nome');
        $existe->execute([':nome' => $nome]);
        if ($existe->fetch()) {
            $erro = 'Já existe uma categoria com esse nome.';
        } else {
            $pdo->prepare('INSERT INTO categorias (nome) VALUES (:nome)')->execute([':nome' => $nome]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'deletar') {
    $id = (int) $_POST['id_categoria'];
    $emUso = $pdo->prepare('SELECT COUNT(*) FROM produtos WHERE id_categoria = :id');
    $emUso->execute([':id' => $id]);
    if ($emUso->fetchColumn() > 0) {
        $erro = 'Essa categoria está em uso por produtos e não pode ser excluída.';
    } else {
        $pdo->prepare('DELETE FROM categorias WHERE id_categoria = :id')->execute([':id' => $id]);
    }
}

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Categorias</title></head>
<body>
    <h1>Categorias</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="acao" value="criar">
        <input type="text" name="nome" placeholder="Nome da categoria" required>
        <button type="submit">Adicionar</button>
    </form>
    <table border="1" cellpadding="6">
        <?php foreach ($categorias as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['nome']) ?></td>
            <td>
                <a href="/produtos/variacoes.php?id_categoria=<?= $c['id_categoria'] ?>">variações</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta categoria?');">
                    <input type="hidden" name="acao" value="deletar">
                    <input type="hidden" name="id_categoria" value="<?= $c['id_categoria'] ?>">
                    <button type="submit">excluir</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Visit `/produtos/categorias.php`, create "Roupas" and "Eletrodomésticos". Confirm both list. Try creating "Roupas" again — expect duplicate-name error. Delete "Eletrodomésticos" — expect it to disappear (nothing references it yet, so deletion succeeds).

- [ ] **Step 3: Commit**

```bash
git add produtos/categorias.php
git commit -m "feat: add categorias CRUD"
```

---

### Task 6: Variações + valores CRUD, associated per categoria

**Files:**
- Create: `produtos/variacoes.php`

**Interfaces:**
- Consumes: `exigirLogin()`, `$pdo`, `categorias` (Task 5)
- Produces: `variacoes`, `variacao_valores`, `categoria_variacoes` rows consumed by Task 7 (product creation reads which variações are active for the chosen categoria, and their valores)

- [ ] **Step 1: Write `produtos/variacoes.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id_categoria = (int) ($_GET['id_categoria'] ?? 0);
$stmtCat = $pdo->prepare('SELECT * FROM categorias WHERE id_categoria = :id');
$stmtCat->execute([':id' => $id_categoria]);
$categoria = $stmtCat->fetch();
if (!$categoria) {
    http_response_code(404);
    echo 'Categoria não encontrada.';
    exit;
}

$erro = '';

// Cria uma variação nova (ex: "Cor") já com seus valores (ex: "Azul, Vermelho")
// e a ativa direto para esta categoria.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_variacao') {
    $nome = trim($_POST['nome'] ?? '');
    $valoresTexto = trim($_POST['valores'] ?? '');
    $valores = array_filter(array_map('trim', explode(',', $valoresTexto)));

    if ($nome === '' || empty($valores)) {
        $erro = 'Informe o nome da variação e ao menos um valor (separados por vírgula).';
    } else {
        $stmtV = $pdo->prepare('SELECT id_variacao FROM variacoes WHERE nome = :nome');
        $stmtV->execute([':nome' => $nome]);
        $variacao = $stmtV->fetch();

        if (!$variacao) {
            $pdo->prepare('INSERT INTO variacoes (nome) VALUES (:nome)')->execute([':nome' => $nome]);
            $id_variacao = (int) $pdo->lastInsertId();
        } else {
            $id_variacao = (int) $variacao['id_variacao'];
        }

        foreach ($valores as $valor) {
            $existeValor = $pdo->prepare('SELECT id_valor FROM variacao_valores WHERE id_variacao = :iv AND valor = :valor');
            $existeValor->execute([':iv' => $id_variacao, ':valor' => $valor]);
            if (!$existeValor->fetch()) {
                $pdo->prepare('INSERT INTO variacao_valores (id_variacao, valor) VALUES (:iv, :valor)')
                    ->execute([':iv' => $id_variacao, ':valor' => $valor]);
            }
        }

        $existeAssoc = $pdo->prepare('SELECT 1 FROM categoria_variacoes WHERE id_categoria = :ic AND id_variacao = :iv');
        $existeAssoc->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
        if (!$existeAssoc->fetch()) {
            $pdo->prepare('INSERT INTO categoria_variacoes (id_categoria, id_variacao) VALUES (:ic, :iv)')
                ->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
        }
    }
}

// Remove a associação da variação com esta categoria (não deleta a variação em si,
// que pode estar em uso por outras categorias).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover_associacao') {
    $id_variacao = (int) $_POST['id_variacao'];
    $emUso = $pdo->prepare(
        'SELECT COUNT(*) FROM produto_variacao_valores pvv
         JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
         JOIN produto_variacoes pv ON pv.id_produto_variacao = pvv.id_produto_variacao
         JOIN produtos p ON p.id_produto = pv.id_produto
         WHERE vv.id_variacao = :iv AND p.id_categoria = :ic'
    );
    $emUso->execute([':iv' => $id_variacao, ':ic' => $id_categoria]);
    if ($emUso->fetchColumn() > 0) {
        $erro = 'Essa variação está em uso por produtos desta categoria e não pode ser removida.';
    } else {
        $pdo->prepare('DELETE FROM categoria_variacoes WHERE id_categoria = :ic AND id_variacao = :iv')
            ->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
    }
}

$variacoesAtivas = $pdo->prepare(
    'SELECT v.id_variacao, v.nome, GROUP_CONCAT(vv.valor SEPARATOR ", ") AS valores
     FROM categoria_variacoes cv
     JOIN variacoes v ON v.id_variacao = cv.id_variacao
     LEFT JOIN variacao_valores vv ON vv.id_variacao = v.id_variacao
     WHERE cv.id_categoria = :ic
     GROUP BY v.id_variacao, v.nome
     ORDER BY v.nome'
);
$variacoesAtivas->execute([':ic' => $id_categoria]);
$variacoes = $variacoesAtivas->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Variações — <?= htmlspecialchars($categoria['nome']) ?></title></head>
<body>
    <h1>Variações de "<?= htmlspecialchars($categoria['nome']) ?>"</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="acao" value="criar_variacao">
        <label>Nome da variação (ex: Cor)<br><input type="text" name="nome" required></label><br>
        <label>Valores, separados por vírgula (ex: Azul, Vermelho)<br><input type="text" name="valores" required style="width:400px;"></label><br>
        <button type="submit">Adicionar / atualizar</button>
    </form>
    <table border="1" cellpadding="6">
        <tr><th>Variação</th><th>Valores</th><th></th></tr>
        <?php foreach ($variacoes as $v): ?>
        <tr>
            <td><?= htmlspecialchars($v['nome']) ?></td>
            <td><?= htmlspecialchars($v['valores'] ?? '') ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Remover esta variação desta categoria?');">
                    <input type="hidden" name="acao" value="remover_associacao">
                    <input type="hidden" name="id_variacao" value="<?= $v['id_variacao'] ?>">
                    <button type="submit">remover</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p><a href="/produtos/categorias.php">Voltar para categorias</a></p>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

From `/produtos/categorias.php`, click "variações" on "Roupas". Add variação "Cor" with valores "Azul, Vermelho, Verde". Add variação "Tamanho" with valores "P, M, G". Confirm both list with their valores. Try removing "Cor" — expect it to succeed (nothing uses it yet). Re-add it (needed for Task 7's verification).

- [ ] **Step 3: Commit**

```bash
git add produtos/variacoes.php
git commit -m "feat: add configurable variacoes CRUD per categoria"
```

---

### Task 7: Product creation with dynamic variation combinations

**Files:**
- Create: `produtos/novo.php`
- Create: `produtos/ajax/gerar_combinacoes.php`
- Create: `produtos/ajax/listar_variacoes_categoria.php`

**Interfaces:**
- Consumes: `exigirLogin()`, `$pdo`, `categorias`/`variacoes`/`variacao_valores`/`categoria_variacoes` (Tasks 5-6)
- Produces: `produtos`, `produto_variacoes`, `produto_variacao_valores` rows. Every `produtos` row has **at least one** `produto_variacoes` row (the "default" row when no variação is activated) — Task 8 and later sub-projects (PDV, Loja Online) rely on always being able to read stock/price from `produto_variacoes`, never from `produtos` directly.

- [ ] **Step 1: Write `produtos/ajax/gerar_combinacoes.php`** (AJAX endpoint: given a categoria + chosen variação ids, returns the cartesian product of their valores as JSON, so the form can render one stock/price row per combination before submit)

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

header('Content-Type: application/json');

$id_categoria = (int) ($_GET['id_categoria'] ?? 0);
$ids_variacao = array_filter(array_map('intval', explode(',', $_GET['variacoes'] ?? '')));

if (empty($ids_variacao)) {
    echo json_encode(['combinacoes' => [[]]]);
    exit;
}

$grupos = [];
foreach ($ids_variacao as $id_variacao) {
    $stmt = $pdo->prepare(
        'SELECT vv.id_valor, vv.valor, v.nome AS nome_variacao
         FROM variacao_valores vv
         JOIN variacoes v ON v.id_variacao = vv.id_variacao
         JOIN categoria_variacoes cv ON cv.id_variacao = vv.id_variacao AND cv.id_categoria = :ic
         WHERE vv.id_variacao = :iv
         ORDER BY vv.valor'
    );
    $stmt->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
    $valores = $stmt->fetchAll();
    if ($valores) {
        $grupos[] = $valores;
    }
}

$combinacoes = [[]];
foreach ($grupos as $grupo) {
    $novasCombinacoes = [];
    foreach ($combinacoes as $combinacaoAtual) {
        foreach ($grupo as $valor) {
            $novasCombinacoes[] = array_merge($combinacaoAtual, [$valor]);
        }
    }
    $combinacoes = $novasCombinacoes;
}

echo json_encode(['combinacoes' => $combinacoes]);
```

- [ ] **Step 2: Write `produtos/novo.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '') ?: null;
    $id_categoria = (int) ($_POST['id_categoria'] ?? 0);
    $condicao = $_POST['condicao'] === 'novo' ? 'novo' : 'usado';
    $preco_base = (float) str_replace(',', '.', $_POST['preco_base'] ?? '0');
    // combinacoes[]: cada item é um JSON {"valores":[id_valor,...],"preco":x,"estoque":y}
    $combinacoesJson = $_POST['combinacoes'] ?? '[]';
    $combinacoes = json_decode($combinacoesJson, true) ?: [];

    if ($nome === '' || $id_categoria <= 0 || $preco_base <= 0) {
        $erro = 'Preencha nome, categoria e um preço base válido.';
    } elseif (empty($combinacoes)) {
        $erro = 'É preciso informar estoque de ao menos uma combinação (ou deixar sem variação para usar o padrão).';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO produtos (nome, descricao, id_categoria, condicao, preco_base) VALUES (:nome, :descricao, :ic, :condicao, :preco_base)'
            );
            $stmt->execute([
                ':nome' => $nome,
                ':descricao' => $descricao,
                ':ic' => $id_categoria,
                ':condicao' => $condicao,
                ':preco_base' => $preco_base,
            ]);
            $id_produto = (int) $pdo->lastInsertId();

            foreach ($combinacoes as $combinacao) {
                $precoCombinacao = isset($combinacao['preco']) && $combinacao['preco'] !== ''
                    ? (float) $combinacao['preco']
                    : null;
                $estoque = (int) ($combinacao['estoque'] ?? 0);

                $stmtPv = $pdo->prepare(
                    'INSERT INTO produto_variacoes (id_produto, preco, estoque) VALUES (:ip, :preco, :estoque)'
                );
                $stmtPv->execute([':ip' => $id_produto, ':preco' => $precoCombinacao, ':estoque' => $estoque]);
                $id_produto_variacao = (int) $pdo->lastInsertId();

                foreach (($combinacao['valores'] ?? []) as $id_valor) {
                    $pdo->prepare(
                        'INSERT INTO produto_variacao_valores (id_produto_variacao, id_valor) VALUES (:ipv, :iv)'
                    )->execute([':ipv' => $id_produto_variacao, ':iv' => (int) $id_valor]);
                }
            }

            $pdo->commit();
            header('Location: /produtos/editar.php?id=' . $id_produto . '&criado=1');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Novo produto</title></head>
<body>
    <h1>Novo produto</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post" id="form-produto">
        <label>Nome<br><input type="text" name="nome" required></label><br>
        <label>Descrição<br><textarea name="descricao"></textarea></label><br>
        <label>Categoria<br>
            <select name="id_categoria" id="id_categoria" required>
                <option value="">Selecione</option>
                <?php foreach ($categorias as $c): ?>
                <option value="<?= $c['id_categoria'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </label><br>
        <label>Condição<br>
            <select name="condicao">
                <option value="usado">Usado</option>
                <option value="novo">Novo</option>
            </select>
        </label><br>
        <label>Preço base (R$)<br><input type="text" name="preco_base" required></label><br>

        <div id="variacoes-disponiveis"></div>

        <h3>Combinações e estoque</h3>
        <div id="combinacoes-container"></div>
        <input type="hidden" name="combinacoes" id="combinacoes-input">

        <button type="submit">Salvar produto</button>
    </form>

<script>
document.getElementById('id_categoria').addEventListener('change', carregarVariacoes);

function carregarVariacoes() {
    const idCategoria = document.getElementById('id_categoria').value;
    const container = document.getElementById('variacoes-disponiveis');
    container.innerHTML = '';
    document.getElementById('combinacoes-container').innerHTML = '';
    if (!idCategoria) return;

    fetch('/produtos/ajax/listar_variacoes_categoria.php?id_categoria=' + idCategoria)
        .then(r => r.json())
        .then(data => {
            data.variacoes.forEach(v => {
                const label = document.createElement('label');
                label.style.display = 'block';
                label.innerHTML = '<input type="checkbox" class="chk-variacao" value="' + v.id_variacao + '"> ' + v.nome;
                container.appendChild(label);
            });
            container.querySelectorAll('.chk-variacao').forEach(chk => {
                chk.addEventListener('change', atualizarCombinacoes);
            });
            // Renderiza a combinação "Padrão" imediatamente, mesmo sem nenhuma
            // variação marcada — sem isso não haveria campo de estoque/preço
            // nenhum pra um produto sem variação, e ele seria salvo com estoque 0.
            atualizarCombinacoes();
        });
}

function atualizarCombinacoes() {
    const idCategoria = document.getElementById('id_categoria').value;
    const marcadas = Array.from(document.querySelectorAll('.chk-variacao:checked')).map(c => c.value);
    const container = document.getElementById('combinacoes-container');
    container.innerHTML = 'Carregando...';

    fetch('/produtos/ajax/gerar_combinacoes.php?id_categoria=' + idCategoria + '&variacoes=' + marcadas.join(','))
        .then(r => r.json())
        .then(data => {
            container.innerHTML = '';
            data.combinacoes.forEach((combinacao, index) => {
                const nomeCombinacao = combinacao.map(v => v.valor).join(' / ') || 'Padrão (sem variação)';
                const div = document.createElement('div');
                div.innerHTML = '<strong>' + nomeCombinacao + '</strong> — ' +
                    'Estoque: <input type="number" min="0" class="input-estoque" value="0"> ' +
                    'Preço (deixe em branco para usar o preço base): <input type="text" class="input-preco">';
                div.dataset.valores = JSON.stringify(combinacao.map(v => v.id_valor));
                container.appendChild(div);
            });
        });
}

document.getElementById('form-produto').addEventListener('submit', function (e) {
    const linhas = document.querySelectorAll('#combinacoes-container > div');
    const combinacoes = Array.from(linhas).map(div => ({
        valores: JSON.parse(div.dataset.valores),
        estoque: div.querySelector('.input-estoque').value,
        preco: div.querySelector('.input-preco').value,
    }));
    document.getElementById('combinacoes-input').value = JSON.stringify(combinacoes);
});
</script>
</body>
</html>
```

- [ ] **Step 3: Write `produtos/ajax/listar_variacoes_categoria.php`** (small AJAX endpoint the form above depends on — lists which variações are active for a categoria, so the checkboxes can be rendered)

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

header('Content-Type: application/json');

$id_categoria = (int) ($_GET['id_categoria'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT v.id_variacao, v.nome FROM categoria_variacoes cv
     JOIN variacoes v ON v.id_variacao = cv.id_variacao
     WHERE cv.id_categoria = :ic ORDER BY v.nome'
);
$stmt->execute([':ic' => $id_categoria]);
echo json_encode(['variacoes' => $stmt->fetchAll()]);
```

- [ ] **Step 4: Verify manually**

Visit `/produtos/novo.php`. Fill nome "Camiseta Vintage", categoria "Roupas", preço base `50,00`. Confirm the "Cor" and "Tamanho" checkboxes appear (from Task 6). Check "Tamanho" only — confirm 3 combination rows appear (P, M, G), each with an estoque input. Set P=2, M=3, G=0, submit.
Verify in the database: `mysql -u root sistema_veronica -e "SELECT * FROM produto_variacoes"` — expect 3 rows for this product with `estoque` 2, 3, 0. `SELECT * FROM produto_variacao_valores` — expect each row linked to the right `id_valor`.
Then create a second product with no variação checked at all — confirm it still saves successfully with exactly one `produto_variacoes` row (the "Padrão" case).

- [ ] **Step 5: Commit**

```bash
git add produtos/novo.php produtos/ajax/gerar_combinacoes.php produtos/ajax/listar_variacoes_categoria.php
git commit -m "feat: add product creation with dynamic variation combinations"
```

---

### Task 8: Product list + edit (stock/price per combination, ativo toggle)

**Files:**
- Create: `produtos/lista.php`
- Create: `produtos/editar.php`

**Interfaces:**
- Consumes: `exigirLogin()`, `$pdo`, `produtos`/`produto_variacoes`/`produto_variacao_valores` (Task 7)
- Produces: nothing new consumed by later tasks (photos in Task 9 attach to the same `id_produto` shown here)

- [ ] **Step 1: Write `produtos/lista.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$produtos = $pdo->query(
    'SELECT p.id_produto, p.nome, p.preco_base, p.ativo, c.nome AS categoria,
            COALESCE(SUM(pv.estoque), 0) AS estoque_total
     FROM produtos p
     JOIN categorias c ON c.id_categoria = p.id_categoria
     LEFT JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
     GROUP BY p.id_produto, p.nome, p.preco_base, p.ativo, c.nome
     ORDER BY p.criado_em DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Produtos</title></head>
<body>
    <h1>Produtos</h1>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Produto criado com sucesso.</p><?php endif; ?>
    <p><a href="/produtos/novo.php">+ Novo produto</a> | <a href="/produtos/categorias.php">Categorias</a></p>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>Categoria</th><th>Preço base</th><th>Estoque total</th><th>Ativo</th><th></th></tr>
        <?php foreach ($produtos as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['nome']) ?></td>
            <td><?= htmlspecialchars($p['categoria']) ?></td>
            <td>R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></td>
            <td><?= (int) $p['estoque_total'] ?></td>
            <td><?= $p['ativo'] ? 'Sim' : 'Não' ?></td>
            <td><a href="/produtos/editar.php?id=<?= $p['id_produto'] ?>">editar</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
```

- [ ] **Step 2: Write `produtos/editar.php`** (edit base fields, toggle ativo, edit stock/price per existing combination, delete product — photo management is added on top of this page in Task 9)

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id_produto = (int) ($_GET['id'] ?? 0);
$stmtP = $pdo->prepare('SELECT * FROM produtos WHERE id_produto = :id');
$stmtP->execute([':id' => $id_produto]);
$produto = $stmtP->fetch();

if (!$produto) {
    http_response_code(404);
    echo 'Produto não encontrado.';
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '') ?: null;
    $preco_base = (float) str_replace(',', '.', $_POST['preco_base'] ?? '0');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '' || $preco_base <= 0) {
        $erro = 'Nome e preço base são obrigatórios.';
    } else {
        $pdo->prepare('UPDATE produtos SET nome = :nome, descricao = :descricao, preco_base = :preco_base, ativo = :ativo WHERE id_produto = :id')
            ->execute([':nome' => $nome, ':descricao' => $descricao, ':preco_base' => $preco_base, ':ativo' => $ativo, ':id' => $id_produto]);

        foreach ($_POST['estoque'] ?? [] as $id_pv => $valor) {
            $pdo->prepare('UPDATE produto_variacoes SET estoque = :estoque WHERE id_produto_variacao = :id AND id_produto = :ip')
                ->execute([':estoque' => (int) $valor, ':id' => (int) $id_pv, ':ip' => $id_produto]);
        }
        foreach ($_POST['preco'] ?? [] as $id_pv => $valor) {
            $precoCombinacao = $valor === '' ? null : (float) str_replace(',', '.', $valor);
            $pdo->prepare('UPDATE produto_variacoes SET preco = :preco WHERE id_produto_variacao = :id AND id_produto = :ip')
                ->execute([':preco' => $precoCombinacao, ':id' => (int) $id_pv, ':ip' => $id_produto]);
        }

        header('Location: /produtos/editar.php?id=' . $id_produto . '&atualizado=1');
        exit;
    }
}

$combinacoes = $pdo->prepare(
    'SELECT pv.id_produto_variacao, pv.preco, pv.estoque,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao
     FROM produto_variacoes pv
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE pv.id_produto = :ip
     GROUP BY pv.id_produto_variacao, pv.preco, pv.estoque'
);
$combinacoes->execute([':ip' => $id_produto]);
$listaCombinacoes = $combinacoes->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Editar produto</title></head>
<body>
    <h1>Editar produto</h1>
    <?php if (isset($_GET['atualizado'])): ?><p style="color:green;">Produto atualizado.</p><?php endif; ?>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Produto criado com sucesso.</p><?php endif; ?>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar">
        <label>Nome<br><input type="text" name="nome" value="<?= htmlspecialchars($produto['nome']) ?>" required></label><br>
        <label>Descrição<br><textarea name="descricao"><?= htmlspecialchars($produto['descricao'] ?? '') ?></textarea></label><br>
        <label>Preço base (R$)<br><input type="text" name="preco_base" value="<?= number_format($produto['preco_base'], 2, ',', '') ?>" required></label><br>
        <label><input type="checkbox" name="ativo" <?= $produto['ativo'] ? 'checked' : '' ?>> Ativo (visível na loja)</label><br>

        <h3>Combinações</h3>
        <table border="1" cellpadding="6">
            <tr><th>Combinação</th><th>Estoque</th><th>Preço (branco = usa o base)</th></tr>
            <?php foreach ($listaCombinacoes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['descricao'] ?? 'Padrão (sem variação)') ?></td>
                <td><input type="number" min="0" name="estoque[<?= $c['id_produto_variacao'] ?>]" value="<?= (int) $c['estoque'] ?>"></td>
                <td><input type="text" name="preco[<?= $c['id_produto_variacao'] ?>]" value="<?= $c['preco'] !== null ? number_format($c['preco'], 2, ',', '') : '' ?>"></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <button type="submit">Salvar alterações</button>
    </form>

    <form method="post" action="/produtos/ajax/deletar_produto.php" onsubmit="return confirm('Excluir este produto e suas fotos definitivamente?');">
        <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
        <button type="submit">Excluir produto</button>
    </form>

    <p><a href="/produtos/lista.php">Voltar</a></p>
</body>
</html>
```

- [ ] **Step 3: Verify manually**

From `/produtos/lista.php`, confirm both products created in Task 7 appear with correct estoque total (5 for the "Camiseta Vintage" with P=2+M=3, 0 for the no-variação product if you set 0, or whatever value you used). Click "editar" on "Camiseta Vintage" — confirm the 3 combination rows (P, M, G) show with their current stock. Change M's stock to 10, save, confirm `/produtos/lista.php` now shows estoque total 12. Uncheck "Ativo", save, confirm the list shows "Não".

- [ ] **Step 4: Commit**

```bash
git add produtos/lista.php produtos/editar.php
git commit -m "feat: add product list and edit with per-combination stock"
```

---

### Task 9: Photo upload (GD resize) + display

**Files:**
- Create: `produtos/ajax/upload_foto.php`
- Modify: `produtos/editar.php` (add photo upload form + thumbnail gallery)

**Interfaces:**
- Consumes: `exigirLogin()`, `$pdo`, `produtos` (Task 7/8)
- Produces: `produto_fotos` rows + files under `assets/img/produtos/{id_produto}/`; consumed by Task 10 (deletion) and by the future Loja Online sub-project (product gallery)

- [ ] **Step 1: Write `produtos/ajax/upload_foto.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

$id_produto = (int) ($_POST['id_produto'] ?? 0);

$stmtP = $pdo->prepare('SELECT id_produto FROM produtos WHERE id_produto = :id');
$stmtP->execute([':id' => $id_produto]);
if (!$stmtP->fetch()) {
    header('Location: /produtos/lista.php?upload_status=error&msg=produto_nao_encontrado');
    exit;
}

if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error');
    exit;
}

$stmtCount = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(ordem), 0) FROM produto_fotos WHERE id_produto = :id');
$stmtCount->execute([':id' => $id_produto]);
[$totalFotos, $maiorOrdem] = $stmtCount->fetch(PDO::FETCH_NUM);

if ($totalFotos >= 5) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error&msg=limite_5_fotos');
    exit;
}

$targetWidth = 473;
$targetHeight = 400;
$targetDir = __DIR__ . '/../../assets/img/produtos/' . $id_produto . '/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$novaOrdem = $maiorOrdem + 1;
$destino = $targetDir . $novaOrdem . '.png';

$info = getimagesize($_FILES['foto']['tmp_name']);
$mime = $info['mime'] ?? '';

$origem = match ($mime) {
    'image/jpeg' => imagecreatefromjpeg($_FILES['foto']['tmp_name']),
    'image/png' => imagecreatefrompng($_FILES['foto']['tmp_name']),
    'image/gif' => imagecreatefromgif($_FILES['foto']['tmp_name']),
    default => null,
};

// imagecreatefrom*() returns false (not null) on a decode failure, even when
// getimagesize() already confirmed the mime type — must catch both.
if ($origem === null || $origem === false) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error&msg=formato_invalido');
    exit;
}

$novaImagem = imagecreatetruecolor($targetWidth, $targetHeight);
imagealphablending($novaImagem, false);
imagesavealpha($novaImagem, true);
imagecopyresampled($novaImagem, $origem, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($origem), imagesy($origem));
$sucesso = imagepng($novaImagem, $destino, 9);
imagedestroy($origem);
imagedestroy($novaImagem);

if (!$sucesso) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error&msg=falha_processamento');
    exit;
}

$caminhoRelativo = 'assets/img/produtos/' . $id_produto . '/' . $novaOrdem . '.png';
$pdo->prepare('INSERT INTO produto_fotos (id_produto, ordem, caminho_arquivo) VALUES (:ip, :ordem, :caminho)')
    ->execute([':ip' => $id_produto, ':ordem' => $novaOrdem, ':caminho' => $caminhoRelativo]);

header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=success');
exit;
```

- [ ] **Step 2: Add photo upload form and gallery to `produtos/editar.php`** — insert this block right before the "Excluir produto" form:

```php
<?php
$fotos = $pdo->prepare('SELECT id_foto, ordem, caminho_arquivo FROM produto_fotos WHERE id_produto = :ip ORDER BY ordem');
$fotos->execute([':ip' => $id_produto]);
$listaFotos = $fotos->fetchAll();
?>
<h3>Fotos (<?= count($listaFotos) ?>/5)</h3>
<?php if (isset($_GET['upload_status']) && $_GET['upload_status'] === 'error'): ?>
    <p style="color:red;">Falha ao enviar a foto (<?= htmlspecialchars($_GET['msg'] ?? 'erro desconhecido') ?>).</p>
<?php endif; ?>
<div style="display:flex; gap:10px;">
    <?php foreach ($listaFotos as $f): ?>
    <div>
        <img src="/<?= htmlspecialchars($f['caminho_arquivo']) ?>" width="120" alt="Foto do produto">
        <form method="post" action="/produtos/ajax/deletar_foto.php" onsubmit="return confirm('Remover esta foto?');">
            <input type="hidden" name="id_foto" value="<?= $f['id_foto'] ?>">
            <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
            <button type="submit">remover</button>
        </form>
    </div>
    <?php endforeach; ?>
</div>
<?php if (count($listaFotos) < 5): ?>
<form method="post" action="/produtos/ajax/upload_foto.php" enctype="multipart/form-data">
    <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
    <input type="file" name="foto" accept="image/png,image/jpeg,image/gif" required>
    <button type="submit">Enviar foto</button>
</form>
<?php endif; ?>
```

- [ ] **Step 3: Verify manually**

Open `/produtos/editar.php?id=<id da Camiseta Vintage>`. Upload a JPEG photo. Confirm it appears as a 473x400 PNG thumbnail and that the file exists on disk at `assets/img/produtos/{id}/1.png`. Upload 4 more (5 total), confirm the upload form disappears at 5. Try uploading a 6th via a direct POST (or just confirm the form is gone) — the counting guard in `upload_foto.php` prevents it either way. Try uploading a `.txt` file renamed to `.png` — expect the `formato_invalido` error (since `getimagesize` will fail to identify it as a real image).

- [ ] **Step 4: Commit**

```bash
git add produtos/ajax/upload_foto.php produtos/editar.php
git commit -m "feat: add photo upload with GD resize, max 5 per product"
```

---

### Task 10: Cascade deletion (product + photos from disk, single photo removal)

**Files:**
- Create: `produtos/ajax/deletar_foto.php`
- Create: `produtos/ajax/deletar_produto.php`

**Interfaces:**
- Consumes: `exigirLogin()`, `$pdo`, `produtos`/`produto_fotos` (Tasks 7-9)
- Produces: nothing consumed by later tasks — this is the terminal operation for a product's lifecycle in this sub-project

- [ ] **Step 1: Write `produtos/ajax/deletar_foto.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

$id_foto = (int) ($_POST['id_foto'] ?? 0);
$id_produto = (int) ($_POST['id_produto'] ?? 0);

$stmt = $pdo->prepare('SELECT caminho_arquivo FROM produto_fotos WHERE id_foto = :id AND id_produto = :ip');
$stmt->execute([':id' => $id_foto, ':ip' => $id_produto]);
$foto = $stmt->fetch();

if ($foto) {
    $caminhoAbsoluto = __DIR__ . '/../../' . $foto['caminho_arquivo'];
    if (is_file($caminhoAbsoluto)) {
        unlink($caminhoAbsoluto);
    }
    $pdo->prepare('DELETE FROM produto_fotos WHERE id_foto = :id')->execute([':id' => $id_foto]);
}

header('Location: /produtos/editar.php?id=' . $id_produto);
exit;
```

- [ ] **Step 2: Write `produtos/ajax/deletar_produto.php`**

```php
<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

$id_produto = (int) ($_POST['id_produto'] ?? 0);

$stmt = $pdo->prepare('SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = :ip');
$stmt->execute([':ip' => $id_produto]);
$fotos = $stmt->fetchAll();

foreach ($fotos as $foto) {
    $caminhoAbsoluto = __DIR__ . '/../../' . $foto['caminho_arquivo'];
    if (is_file($caminhoAbsoluto)) {
        unlink($caminhoAbsoluto);
    }
}

$pastaProduto = __DIR__ . '/../../assets/img/produtos/' . $id_produto;
if (is_dir($pastaProduto)) {
    rmdir($pastaProduto);
}

// ON DELETE CASCADE em produto_variacoes, produto_variacao_valores e produto_fotos
// cuida do resto — não há necessidade de apagar essas linhas manualmente aqui.
$pdo->prepare('DELETE FROM produtos WHERE id_produto = :id')->execute([':id' => $id_produto]);

header('Location: /produtos/lista.php?excluido=1');
exit;
```

- [ ] **Step 3: Verify manually**

On the "Camiseta Vintage" product (with photos uploaded in Task 9), remove one photo via the "remover" button. Confirm the file disappears from `assets/img/produtos/{id}/` and the gallery updates. Then click "Excluir produto" and confirm. Verify: `SELECT * FROM produtos WHERE id_produto = X` returns nothing; `SELECT * FROM produto_variacoes WHERE id_produto = X` returns nothing (cascade); the folder `assets/img/produtos/{id}/` no longer exists.

- [ ] **Step 4: Commit**

```bash
git add produtos/ajax/deletar_foto.php produtos/ajax/deletar_produto.php
git commit -m "feat: add cascading product deletion with disk cleanup"
```

---

### Task 11: Store branding config (logo + colors)

**Files:**
- Create: `config_sistema/aparencia.php`

**Interfaces:**
- Consumes: `exigirAdmin()`, `$pdo`
- Produces: `config_loja` row read by the future Loja Online sub-project to theme the storefront

- [ ] **Step 1: Write `config_sistema/aparencia.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_loja = trim($_POST['nome_loja'] ?? '');
    $cor_primaria = trim($_POST['cor_primaria'] ?? '#8B5CF6');
    $cor_secundaria = trim($_POST['cor_secundaria'] ?? '#F472B6');

    if ($nome_loja === '') {
        $erro = 'Informe o nome da loja.';
    } else {
        $logoArquivo = null;
        if (!empty($_FILES['logo']['tmp_name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $info = getimagesize($_FILES['logo']['tmp_name']);
            $mime = $info['mime'] ?? '';
            $origem = match ($mime) {
                'image/jpeg' => imagecreatefromjpeg($_FILES['logo']['tmp_name']),
                'image/png' => imagecreatefrompng($_FILES['logo']['tmp_name']),
                default => null,
            };
            // imagecreatefrom*() returns false (not null) on a decode failure, even when
            // getimagesize() already confirmed the mime type — must catch both.
            if ($origem !== null && $origem !== false) {
                $dir = __DIR__ . '/../assets/img/loja/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                imagepng($origem, $dir . 'logo.png', 9);
                imagedestroy($origem);
                $logoArquivo = 'assets/img/loja/logo.png';
            } else {
                $erro = 'Formato de logo inválido (use JPEG ou PNG).';
            }
        }

        if ($erro === '') {
            if ($logoArquivo !== null) {
                $pdo->prepare('UPDATE config_loja SET nome_loja = :nome, cor_primaria = :cp, cor_secundaria = :cs, logo_arquivo = :logo WHERE id_config = 1')
                    ->execute([':nome' => $nome_loja, ':cp' => $cor_primaria, ':cs' => $cor_secundaria, ':logo' => $logoArquivo]);
            } else {
                $pdo->prepare('UPDATE config_loja SET nome_loja = :nome, cor_primaria = :cp, cor_secundaria = :cs WHERE id_config = 1')
                    ->execute([':nome' => $nome_loja, ':cp' => $cor_primaria, ':cs' => $cor_secundaria]);
            }
            header('Location: /config_sistema/aparencia.php?salvo=1');
            exit;
        }
    }
}

$config = $pdo->query('SELECT * FROM config_loja WHERE id_config = 1')->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Aparência da loja</title></head>
<body>
    <h1>Aparência da loja</h1>
    <?php if (isset($_GET['salvo'])): ?><p style="color:green;">Configuração salva.</p><?php endif; ?>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if (!empty($config['logo_arquivo'])): ?>
        <img src="/<?= htmlspecialchars($config['logo_arquivo']) ?>?v=<?= time() ?>" width="150" alt="Logo atual"><br>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <label>Nome da loja<br><input type="text" name="nome_loja" value="<?= htmlspecialchars($config['nome_loja']) ?>" required></label><br>
        <label>Logo (JPEG ou PNG)<br><input type="file" name="logo" accept="image/png,image/jpeg"></label><br>
        <label>Cor primária<br><input type="color" name="cor_primaria" value="<?= htmlspecialchars($config['cor_primaria']) ?>"></label><br>
        <label>Cor secundária<br><input type="color" name="cor_secundaria" value="<?= htmlspecialchars($config['cor_secundaria']) ?>"></label><br>
        <button type="submit">Salvar</button>
    </form>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Log in as Admin, visit `/config_sistema/aparencia.php`. Change nome_loja to "Brechó da Veve", upload a PNG logo, pick colors, save. Confirm redirect with "Configuração salva.", the logo preview shows, and `SELECT * FROM config_loja` reflects the new values. Log in as a Funcionário and visit the same URL — expect HTTP 403.

- [ ] **Step 3: Commit**

```bash
git add config_sistema/aparencia.php
git commit -m "feat: add store branding config (logo and colors)"
```

---

### Task 12: Delivery methods config

**Files:**
- Create: `config_sistema/entrega.php`

**Interfaces:**
- Consumes: `exigirAdmin()`, `$pdo`, seeded `formas_entrega` row (Task 1: "Retirar na loja")
- Produces: `formas_entrega` rows read by the future Loja Online sub-project's checkout

- [ ] **Step 1: Write `config_sistema/entrega.php`**

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    $prazo_dias = $_POST['prazo_dias'] !== '' ? (int) $_POST['prazo_dias'] : null;
    $custo = (float) str_replace(',', '.', $_POST['custo'] ?? '0');

    if ($nome === '') {
        $erro = 'Informe o nome da forma de entrega.';
    } else {
        $pdo->prepare('INSERT INTO formas_entrega (nome, tipo, prazo_dias, custo, ativo, fixa) VALUES (:nome, "entrega", :prazo, :custo, 1, 0)')
            ->execute([':nome' => $nome, ':prazo' => $prazo_dias, ':custo' => $custo]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_retirada') {
    $prazo_dias = (int) ($_POST['prazo_retirada'] ?? 0);
    $pdo->prepare('UPDATE formas_entrega SET prazo_dias = :prazo WHERE fixa = 1')->execute([':prazo' => $prazo_dias]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_ativo') {
    $id = (int) $_POST['id_entrega'];
    $pdo->prepare('UPDATE formas_entrega SET ativo = NOT ativo WHERE id_entrega = :id AND fixa = 0')->execute([':id' => $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'deletar') {
    $id = (int) $_POST['id_entrega'];
    $pdo->prepare('DELETE FROM formas_entrega WHERE id_entrega = :id AND fixa = 0')->execute([':id' => $id]);
}

$formas = $pdo->query('SELECT * FROM formas_entrega ORDER BY fixa DESC, nome')->fetchAll();
$retirada = array_values(array_filter($formas, fn($f) => (int) $f['fixa'] === 1))[0] ?? null;
$entregas = array_values(array_filter($formas, fn($f) => (int) $f['fixa'] === 0));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Formas de entrega</title></head>
<body>
    <h1>Formas de entrega</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <?php if ($retirada): ?>
    <h3>Retirar na loja (fixa)</h3>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar_retirada">
        <label>Prazo de tolerância para retirada (dias)<br>
            <input type="number" min="0" name="prazo_retirada" value="<?= (int) $retirada['prazo_dias'] ?>">
        </label>
        <button type="submit">Salvar prazo</button>
    </form>
    <?php endif; ?>

    <h3>Outras formas de entrega</h3>
    <form method="post">
        <input type="hidden" name="acao" value="criar">
        <input type="text" name="nome" placeholder="Nome (ex: Motoboy)" required>
        <input type="number" name="prazo_dias" placeholder="Prazo em dias">
        <input type="text" name="custo" placeholder="Custo (R$)">
        <button type="submit">Adicionar</button>
    </form>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>Prazo (dias)</th><th>Custo</th><th>Ativo</th><th></th></tr>
        <?php foreach ($entregas as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['nome']) ?></td>
            <td><?= $e['prazo_dias'] !== null ? (int) $e['prazo_dias'] : '—' ?></td>
            <td>R$ <?= number_format($e['custo'], 2, ',', '.') ?></td>
            <td><?= $e['ativo'] ? 'Sim' : 'Não' ?></td>
            <td>
                <form method="post" style="display:inline">
                    <input type="hidden" name="acao" value="alternar_ativo">
                    <input type="hidden" name="id_entrega" value="<?= $e['id_entrega'] ?>">
                    <button type="submit"><?= $e['ativo'] ? 'desativar' : 'ativar' ?></button>
                </form>
                <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta forma de entrega?');">
                    <input type="hidden" name="acao" value="deletar">
                    <input type="hidden" name="id_entrega" value="<?= $e['id_entrega'] ?>">
                    <button type="submit">excluir</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Visit `/config_sistema/entrega.php` as Admin. Confirm "Retirar na loja" shows with the seeded 5-day prazo. Change it to 7, save, confirm it persists. Add "Motoboy" with prazo 2 and custo 15,00. Confirm it lists, toggle it to "desativar", confirm state flips. Try deleting "Retirar na loja" by crafting a POST with its id — expect it to survive, since `fixa = 0` is required by the delete query and this row has `fixa = 1`.

- [ ] **Step 3: Commit**

```bash
git add config_sistema/entrega.php
git commit -m "feat: add delivery methods config with fixed pickup option"
```

---

## Self-Review Notes

- **Spec coverage:** login+perfis (Task 2-3), clientes com WhatsApp obrigatório/único (Task 4), categorias (Task 5), variações configuráveis por categoria (Task 6), produto com combinações e estoque por combinação (Task 7-8), até 5 fotos com GD e deleção em cascata (Task 9-10), aparência da loja (Task 11), formas de entrega incl. retirada fixa com prazo (Task 12). All spec sections have a task.
- **Decoupling from future sales table:** confirmed in Global Constraints and Task 10's comment — `produtos` deletion never checks for sale history, matching the spec's decision that `itens_venda` (built later) stores a snapshot instead of a foreign key.
- **Type consistency check:** `id_produto_variacao` used consistently as the key across Tasks 7, 8, 9 (form field names `estoque[id_produto_variacao]`, `preco[id_produto_variacao]`); `caminho_arquivo` (relative path, no leading slash) used consistently in Tasks 9-10 when building both the `<img src="/...">` and the absolute disk path via `__DIR__ . '/../../' . $caminho`.
