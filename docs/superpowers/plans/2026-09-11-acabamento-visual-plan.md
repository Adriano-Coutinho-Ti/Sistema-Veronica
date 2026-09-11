# Acabamento Visual Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the whole Sistema Veronica a real, mobile-first visual identity and cross-page navigation, without changing any business logic — a configurable theme (light/dark/custom) for the Loja Online, a fixed neutral theme for internal tools, and a shared header/nav on every page.

**Architecture:** Two small, focused CSS files (`assets/css/loja.css`, `assets/css/admin.css`) driven by CSS custom properties; a PHP helper (`includes/tema.php`) that prints the active theme's 4 base color variables from `config_loja`; two shared PHP header partials (`includes/loja_header.php`, `includes/admin_header.php`) that every page includes. Every one of the 24 existing pages gets the same two-line surgical change — a header include right after `<body>`, a closing `</main>` right before `</body>` — no HTML restructuring, since every page already renders its content directly inside `<body>` with no wrapping container (confirmed by reading all 24 files).

**Tech Stack:** PHP 8.5, plain CSS (custom properties + `color-mix()`, no framework/build step), no JavaScript changes.

**Spec:** [docs/superpowers/specs/2026-09-11-acabamento-visual-design.md](../specs/2026-09-11-acabamento-visual-design.md)

## Global Constraints

- **Mobile-first is non-negotiable.** Base CSS rules target small screens; `@media (min-width: 768px)` progressively enhances for larger ones — never the reverse. No fixed pixel width may exceed the viewport. `img { max-width: 100%; height: auto; }` everywhere. Tables use `display: block; overflow-x: auto;` so they scroll instead of breaking page layout. Buttons and form inputs have `min-height: 44px` for comfortable touch targets.
- **No business logic changes anywhere in this plan.** Every task is presentation-only: adding a header include, a CSS link, a theme's color variables. No SQL query, no validation rule, no payment/stock/credit logic changes.
- Theme (`tema` = claro/escuro/personalizado) applies **only to the Loja Online** (`loja/*.php`, except its own `ajax`/`api` endpoints which have no HTML). Internal pages (Fundação, PDV, Linha de Crédito admin) use the fixed, neutral `assets/css/admin.css` palette — never the loja theme.
- Logo uploads save to `assets/img/logo/` (not the old `assets/img/loja/`). Product photos continue unchanged in `assets/img/produtos/`.
- No automated tests — manual verification against a real running server is the standard, explicitly including loading each touched page at a mobile viewport (375px wide) and confirming nothing overflows or breaks.
- All PDO/escaping conventions from prior sub-projects apply where any PHP is touched: bound parameters, `htmlspecialchars()` on dynamic HTML output.
- Every page in this codebase currently renders its content directly inside `<body>` with **no wrapping container element** — confirmed by reading all 24 target files. This is what makes the uniform two-edit-per-page transformation in Tasks 6-8 safe and correct.

---

## File Structure

```
sql/schema_tema.sql
includes/tema.php
includes/loja_header.php
includes/admin_header.php
assets/css/loja.css
assets/css/admin.css
config_sistema/aparencia.php   (modify)
login.php                       (modify — special case, no full header)
loja/index.php                  (modify)
loja/produto.php                (modify)
loja/carrinho.php               (modify)
loja/checkout.php               (modify)
loja/cadastro.php               (modify)
loja/minha_divida.php           (modify)
loja/pedido_status.php          (modify)
caixa/abertura.php              (modify)
caixa/index.php                 (modify)
caixa/pagamento.php             (modify)
caixa/comprovante.php           (modify)
caixa/fechamento.php            (modify)
clientes/lista.php              (modify)
clientes/novo.php               (modify)
clientes/detalhe.php            (modify)
produtos/lista.php              (modify)
produtos/novo.php               (modify)
produtos/editar.php             (modify)
produtos/categorias.php         (modify)
produtos/variacoes.php          (modify)
usuarios/lista.php              (modify)
usuarios/novo.php               (modify)
config_sistema/entrega.php      (modify)
```

---

### Task 1: Database schema + `includes/tema.php`

**Files:**
- Create: `sql/schema_tema.sql`
- Create: `includes/tema.php`

**Interfaces:**
- Consumes: `$pdo`
- Produces: `imprimirVariaveisTema(PDO $pdo): void` — consumed by Task 4's `includes/loja_header.php`

- [ ] **Step 1: Write the SQL migration**

Create `sql/schema_tema.sql`:

```sql
ALTER TABLE config_loja
    ADD COLUMN tema ENUM('claro', 'escuro', 'personalizado') NOT NULL DEFAULT 'claro',
    ADD COLUMN cor_fundo CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN cor_texto CHAR(7) NOT NULL DEFAULT '#1F2937';
```

- [ ] **Step 2: Run the migration and verify**

Run: `mysql -u root sistema_veronica < sql/schema_tema.sql`
Verify: `mysql -u root sistema_veronica -e "DESCRIBE config_loja"` — confirm `tema` (`ENUM`, default `claro`), `cor_fundo`, `cor_texto` present. `mysql -u root sistema_veronica -e "SELECT tema, cor_fundo, cor_texto FROM config_loja"` — confirm the single row shows `claro`/`#FFFFFF`/`#1F2937`.

- [ ] **Step 3: Write `includes/tema.php`**

```php
<?php

/**
 * Lê config_loja e imprime as 4 variáveis CSS de cor do tema ativo, dentro
 * de um <style>. Os temas claro/escuro têm paleta fixa (ignoram as cores
 * salvas no banco) — só o personalizado usa as 4 cores escolhidas pelo
 * lojista. Cores derivadas (bordas, hover, etc) ficam em assets/css/loja.css,
 * calculadas via color-mix() a partir dessas 4 — nunca aqui.
 */
function imprimirVariaveisTema(PDO $pdo): void
{
    $config = $pdo->query('SELECT tema, cor_primaria, cor_secundaria, cor_fundo, cor_texto FROM config_loja WHERE id_config = 1')->fetch();
    $tema = $config['tema'] ?? 'claro';

    $paletasFixas = [
        'claro' => [
            'primaria' => '#8B5CF6',
            'secundaria' => '#F472B6',
            'fundo' => '#FFFFFF',
            'texto' => '#1F2937',
        ],
        'escuro' => [
            'primaria' => '#7C3AED',
            'secundaria' => '#DB2777',
            'fundo' => '#111827',
            'texto' => '#F3F4F6',
        ],
    ];

    if ($tema === 'personalizado') {
        $cores = [
            'primaria' => $config['cor_primaria'],
            'secundaria' => $config['cor_secundaria'],
            'fundo' => $config['cor_fundo'],
            'texto' => $config['cor_texto'],
        ];
    } else {
        $cores = $paletasFixas[$tema] ?? $paletasFixas['claro'];
    }
    ?>
    <style>
        :root {
            --cor-primaria: <?= htmlspecialchars($cores['primaria']) ?>;
            --cor-secundaria: <?= htmlspecialchars($cores['secundaria']) ?>;
            --cor-fundo: <?= htmlspecialchars($cores['fundo']) ?>;
            --cor-texto: <?= htmlspecialchars($cores['texto']) ?>;
        }
    </style>
    <?php
}
```

- [ ] **Step 4: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l includes/tema.php` — expect no syntax errors.

Smoke test: `C:\wamp64\bin\php\php8.5.0\php.exe -r "require 'conecta_bd.php'; require 'includes/tema.php'; imprimirVariaveisTema($pdo);"` — confirm it prints a `<style>` block with the 4 `claro` colors (`#8B5CF6`, `#F472B6`, `#FFFFFF`, `#1F2937`). Then `mysql -u root sistema_veronica -e "UPDATE config_loja SET tema='escuro' WHERE id_config=1"`, re-run the same script, confirm it now prints the `escuro` palette (`#7C3AED`, `#DB2777`, `#111827`, `#F3F4F6`). Then `mysql -u root sistema_veronica -e "UPDATE config_loja SET tema='personalizado', cor_primaria='#FF0000' WHERE id_config=1"`, re-run, confirm it prints `#FF0000` for `--cor-primaria`. Reset: `mysql -u root sistema_veronica -e "UPDATE config_loja SET tema='claro', cor_primaria='#8B5CF6' WHERE id_config=1"`.

- [ ] **Step 5: Commit**

```bash
git add sql/schema_tema.sql includes/tema.php
git commit -m "feat: add theme schema and color-variable engine"
```

---

### Task 2: `assets/css/loja.css` (Loja Online stylesheet)

**Files:**
- Create: `assets/css/loja.css`

**Interfaces:**
- Consumes: CSS custom properties `--cor-primaria`/`--cor-secundaria`/`--cor-fundo`/`--cor-texto` (set by Task 1's `imprimirVariaveisTema()`)
- Produces: CSS classes `.container`, `.site-header`, `.site-header-inner`, `.site-logo`, `.site-nav`, `.site-nav-user`, `.btn`, `.btn-secundario`, `.btn-outline`, `.product-grid`, `.product-card`, `.price`, `.alert`, `.alert-erro`, `.alert-sucesso`, `.table-scroll` — consumed by Task 4's `includes/loja_header.php` and by Tasks 6's page rollout

- [ ] **Step 1: Write `assets/css/loja.css`**

```css
/* assets/css/loja.css — mobile-first, usa as variáveis de includes/tema.php */

:root {
    --cor-texto-suave: color-mix(in srgb, var(--cor-texto) 55%, var(--cor-fundo));
    --cor-borda: color-mix(in srgb, var(--cor-texto) 15%, var(--cor-fundo));
    --cor-primaria-hover: color-mix(in srgb, var(--cor-primaria) 85%, black);
    --cor-secundaria-hover: color-mix(in srgb, var(--cor-secundaria) 85%, black);
    --cor-fundo-alt: color-mix(in srgb, var(--cor-primaria) 6%, var(--cor-fundo));
    --cor-erro: #DC2626;
    --cor-erro-fundo: #FEE2E2;
    --cor-sucesso: #16A34A;
    --cor-sucesso-fundo: #DCFCE7;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--cor-fundo);
    color: var(--cor-texto);
    line-height: 1.5;
}

img { max-width: 100%; height: auto; display: block; }

a { color: var(--cor-primaria); }

h1, h2, h3 { line-height: 1.25; }

.container {
    max-width: 1100px;
    margin: 0 auto;
    padding: 0 16px 32px;
}

/* Cabeçalho / navegação */
.site-header {
    background: var(--cor-fundo-alt);
    border-bottom: 1px solid var(--cor-borda);
    padding: 12px 16px;
    margin-bottom: 24px;
}

.site-header-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.site-logo {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    color: var(--cor-texto);
    font-weight: 700;
    font-size: 1.15rem;
}

.site-logo img { max-height: 40px; width: auto; }

.site-nav {
    display: flex;
    flex-wrap: wrap;
    gap: 4px 16px;
    align-items: center;
    font-size: 0.95rem;
}

.site-nav a { color: var(--cor-texto); text-decoration: none; padding: 6px 4px; }
.site-nav a:hover { color: var(--cor-primaria); }
.site-nav .site-nav-user { color: var(--cor-texto-suave); }

/* Botões — estilizados por elemento, sem precisar de classe em todo lugar */
button, input[type="submit"] {
    display: inline-block;
    padding: 10px 18px;
    min-height: 44px;
    border: none;
    border-radius: 6px;
    background: var(--cor-primaria);
    color: #fff;
    font-size: 1rem;
    cursor: pointer;
    text-align: center;
    font-family: inherit;
}

button:hover, input[type="submit"]:hover { background: var(--cor-primaria-hover); }
button:disabled, input[type="submit"]:disabled { opacity: 0.5; cursor: not-allowed; }

.btn {
    display: inline-block;
    padding: 10px 18px;
    min-height: 44px;
    border-radius: 6px;
    background: var(--cor-primaria);
    color: #fff;
    text-decoration: none;
    text-align: center;
}

.btn-secundario { background: var(--cor-secundaria); }
.btn-secundario:hover { background: var(--cor-secundaria-hover); }

.btn-outline {
    background: transparent;
    color: var(--cor-primaria);
    border: 1px solid var(--cor-primaria);
}

/* Formulários */
label { display: block; margin-bottom: 14px; font-size: 0.95rem; }

input[type="text"], input[type="password"], input[type="email"], input[type="file"],
select, textarea {
    display: block;
    width: 100%;
    margin-top: 4px;
    padding: 10px 12px;
    min-height: 44px;
    border: 1px solid var(--cor-borda);
    border-radius: 6px;
    font-size: 1rem;
    background: var(--cor-fundo);
    color: var(--cor-texto);
    font-family: inherit;
}

textarea { min-height: 88px; }

/* Cartões / grade de produtos */
.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 16px;
    margin-top: 16px;
}

.product-card {
    border: 1px solid var(--cor-borda);
    border-radius: 8px;
    padding: 12px;
    text-decoration: none;
    color: var(--cor-texto);
    background: var(--cor-fundo);
}

.product-card img { width: 100%; aspect-ratio: 1 / 1; object-fit: cover; border-radius: 4px; }
.product-card .price { font-weight: 700; color: var(--cor-primaria); }

/* Alertas */
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.alert-erro { background: var(--cor-erro-fundo); color: var(--cor-erro); }
.alert-sucesso { background: var(--cor-sucesso-fundo); color: var(--cor-sucesso); }

/* Tabelas — nunca deixa a PÁGINA rolar na horizontal, só a tabela */
table {
    display: block;
    overflow-x: auto;
    max-width: 100%;
    border-collapse: collapse;
    width: 100%;
}

th, td { padding: 10px 12px; border-bottom: 1px solid var(--cor-borda); text-align: left; }

@media (min-width: 768px) {
    .container { padding: 0 24px 48px; }
    .product-grid { grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); }
}
```

- [ ] **Step 2: Verify manually**

Create a temporary throwaway HTML file at the repo root (e.g. `_teste_css.html`, NOT committed) that links `assets/css/loja.css` and includes a sample of every component (a `.site-header` block, a `.product-grid` with 2 `.product-card`s, a form with a text input and a button, a `table` with a few columns, a `.alert-erro`). Open it in a real browser tab, confirm it renders with the light theme's colors (since no `includes/tema.php` runs here, `:root` custom properties are undefined — add a manual `<style>:root{--cor-primaria:#8B5CF6;--cor-secundaria:#F472B6;--cor-fundo:#fff;--cor-texto:#1F2937;}</style>` before the `<link>` for this standalone test only). Resize the browser to 375px wide — confirm the header wraps instead of overflowing, the product grid collapses to fewer columns, the table becomes horizontally scrollable instead of breaking the page width, and buttons/inputs are comfortably tappable. Delete the throwaway test file when done.

- [ ] **Step 3: Commit**

```bash
git add assets/css/loja.css
git commit -m "feat: add mobile-first stylesheet for the Loja Online"
```

---

### Task 3: `assets/css/admin.css` (internal tools stylesheet)

**Files:**
- Create: `assets/css/admin.css`

**Interfaces:**
- Consumes: nothing (fixed palette, no theme variables)
- Produces: the same class names as Task 2 (`.container`, `.site-header`, `.site-header-inner`, `.site-logo`, `.site-nav`, `.site-nav-user`, `.card`, `.alert`, `.alert-erro`, `.alert-sucesso`) plus bare-element button/input/table styling — consumed by Task 4's `includes/admin_header.php` and Tasks 7-8's page rollout

- [ ] **Step 1: Write `assets/css/admin.css`**

```css
/* assets/css/admin.css — mobile-first, paleta fixa e neutra (telas internas) */

:root {
    --cor-primaria: #4F46E5;
    --cor-primaria-hover: #4338CA;
    --cor-fundo: #FFFFFF;
    --cor-fundo-alt: #F3F4F6;
    --cor-texto: #1F2937;
    --cor-texto-suave: #6B7280;
    --cor-borda: #E5E7EB;
    --cor-erro: #DC2626;
    --cor-erro-fundo: #FEE2E2;
    --cor-sucesso: #16A34A;
    --cor-sucesso-fundo: #DCFCE7;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: var(--cor-fundo-alt);
    color: var(--cor-texto);
    line-height: 1.5;
}

img { max-width: 100%; height: auto; }

a { color: var(--cor-primaria); }

h1, h2, h3 { line-height: 1.25; }

.container {
    max-width: 1000px;
    margin: 0 auto;
    padding: 0 16px 32px;
}

.site-header {
    background: var(--cor-fundo);
    border-bottom: 1px solid var(--cor-borda);
    padding: 12px 16px;
    margin-bottom: 24px;
}

.site-header-inner {
    max-width: 1000px;
    margin: 0 auto;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.site-logo { text-decoration: none; color: var(--cor-texto); font-weight: 700; font-size: 1.1rem; }

.site-nav { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 0.9rem; }
.site-nav a { color: var(--cor-texto); text-decoration: none; padding: 6px 4px; }
.site-nav a:hover { color: var(--cor-primaria); }
.site-nav .site-nav-user { color: var(--cor-texto-suave); }

button, input[type="submit"] {
    display: inline-block;
    padding: 10px 18px;
    min-height: 44px;
    border: none;
    border-radius: 6px;
    background: var(--cor-primaria);
    color: #fff;
    font-size: 1rem;
    cursor: pointer;
    text-align: center;
    font-family: inherit;
}

button:hover, input[type="submit"]:hover { background: var(--cor-primaria-hover); }
button:disabled, input[type="submit"]:disabled { opacity: 0.5; cursor: not-allowed; }

label { display: block; margin-bottom: 14px; font-size: 0.95rem; }

input[type="text"], input[type="password"], input[type="email"], input[type="number"],
input[type="color"], input[type="file"], select, textarea {
    display: block;
    width: 100%;
    margin-top: 4px;
    padding: 10px 12px;
    min-height: 44px;
    border: 1px solid var(--cor-borda);
    border-radius: 6px;
    font-size: 1rem;
    font-family: inherit;
}

input[type="color"] { padding: 4px; min-height: 44px; }
textarea { min-height: 88px; }

.card {
    background: var(--cor-fundo);
    border: 1px solid var(--cor-borda);
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 16px;
}

.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
.alert-erro { background: var(--cor-erro-fundo); color: var(--cor-erro); }
.alert-sucesso { background: var(--cor-sucesso-fundo); color: var(--cor-sucesso); }

table {
    display: block;
    overflow-x: auto;
    max-width: 100%;
    border-collapse: collapse;
    width: 100%;
    background: var(--cor-fundo);
}

th, td { padding: 10px 12px; border-bottom: 1px solid var(--cor-borda); text-align: left; }
th { background: var(--cor-fundo-alt); }

@media (min-width: 768px) {
    .container { padding: 0 24px 48px; }
}
```

- [ ] **Step 2: Verify manually**

Same technique as Task 2 Step 2: a throwaway standalone HTML file (not committed) exercising `.site-header`, a form, a `table`, `.alert-erro`/`.alert-sucesso`, and a `.card`. Confirm correct rendering at both desktop and 375px-wide mobile viewport — table scrolls instead of breaking the page, header wraps, inputs/buttons are comfortably tappable. Delete the test file when done.

- [ ] **Step 3: Commit**

```bash
git add assets/css/admin.css
git commit -m "feat: add mobile-first stylesheet for internal tools"
```

---

### Task 4: Shared navigation headers

**Files:**
- Create: `includes/loja_header.php`
- Create: `includes/admin_header.php`

**Interfaces:**
- Consumes: `$pdo`, `$_SESSION['id_cliente']`/`$_SESSION['nome_cliente']` (Loja Online), `$_SESSION['id_usuario']`/`$_SESSION['nome']`/`$_SESSION['perfil']` (internal), `imprimirVariaveisTema()` (Task 1), `assets/css/loja.css`/`assets/css/admin.css` (Tasks 2-3)
- Produces: an open `<main class="container">` left unclosed — every page that includes one of these MUST close it with `</main>` right before `</body>` (done uniformly in Tasks 6-8)

- [ ] **Step 1: Write `includes/loja_header.php`**

```php
<?php
/**
 * Cabeçalho compartilhado de toda página da Loja Online — link do CSS,
 * variáveis de tema, logo/nome da loja, e o menu do cliente (muda
 * conforme login). Espera $pdo já definido pelo require de conecta_bd.php
 * no arquivo que inclui este. Abre <main class="container"> sem fechar —
 * cada página que inclui este arquivo precisa fechar com </main> antes do
 * </body>.
 */
require_once __DIR__ . '/tema.php';

$configLoja = $pdo->query('SELECT nome_loja, logo_arquivo FROM config_loja WHERE id_config = 1')->fetch();
?>
<link rel="stylesheet" href="/assets/css/loja.css">
<?php imprimirVariaveisTema($pdo); ?>
<header class="site-header">
    <div class="site-header-inner">
        <a href="/loja/index.php" class="site-logo">
            <?php if (!empty($configLoja['logo_arquivo'])): ?>
                <img src="/<?= htmlspecialchars($configLoja['logo_arquivo']) ?>" alt="<?= htmlspecialchars($configLoja['nome_loja']) ?>">
            <?php else: ?>
                <?= htmlspecialchars($configLoja['nome_loja']) ?>
            <?php endif; ?>
        </a>
        <nav class="site-nav">
            <a href="/loja/index.php">Catálogo</a>
            <?php if (!empty($_SESSION['id_cliente'])): ?>
                <a href="/loja/carrinho.php">Carrinho</a>
                <a href="/loja/minha_divida.php">Meus débitos</a>
                <span class="site-nav-user">Olá, <?= htmlspecialchars($_SESSION['nome_cliente']) ?></span>
                <a href="/loja/logout.php">Sair</a>
            <?php else: ?>
                <a href="/loja/cadastro.php">Entrar / Cadastrar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
```

- [ ] **Step 2: Write `includes/admin_header.php`**

```php
<?php
/**
 * Cabeçalho compartilhado de toda tela interna (Fundação/PDV/Linha de
 * Crédito) — link do CSS de paleta fixa (não usa o tema da loja) e o menu
 * da equipe. Espera $_SESSION já iniciado (includes/auth.php faz isso) no
 * arquivo que inclui este. Abre <main class="container"> sem fechar — cada
 * página que inclui este arquivo precisa fechar com </main> antes do
 * </body>. Não usar em login.php (ainda não há sessão de usuário ali).
 */
?>
<link rel="stylesheet" href="/assets/css/admin.css">
<header class="site-header">
    <div class="site-header-inner">
        <a href="/produtos/lista.php" class="site-logo">Sistema Veronica</a>
        <nav class="site-nav">
            <a href="/produtos/lista.php">Produtos</a>
            <a href="/produtos/categorias.php">Categorias</a>
            <a href="/clientes/lista.php">Clientes</a>
            <a href="/caixa/index.php">Caixa</a>
            <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
                <a href="/usuarios/lista.php">Usuários</a>
                <a href="/config_sistema/aparencia.php">Aparência</a>
                <a href="/config_sistema/entrega.php">Entrega</a>
            <?php endif; ?>
            <span class="site-nav-user">Olá, <?= htmlspecialchars($_SESSION['nome'] ?? '') ?></span>
            <a href="/sair.php">Sair</a>
        </nav>
    </div>
</header>
<main class="container">
```

- [ ] **Step 3: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l includes/loja_header.php` and `-l includes/admin_header.php` — expect no syntax errors. These two files are not directly loadable standalone (they assume `$pdo`/`$_SESSION` from a caller) — full rendering verification happens naturally in Tasks 6-8 when real pages include them; for this task, syntax-check plus a careful read-through against the class names Tasks 2-3 actually defined (`.site-header`, `.site-header-inner`, `.site-logo`, `.site-nav`, `.site-nav-user`) is sufficient.

- [ ] **Step 4: Commit**

```bash
git add includes/loja_header.php includes/admin_header.php
git commit -m "feat: add shared navigation headers for loja and internal pages"
```

---

### Task 5: Update `config_sistema/aparencia.php` (theme selector + new logo path + header)

**Files:**
- Modify: `config_sistema/aparencia.php`

**Interfaces:**
- Consumes: `includes/admin_header.php` (Task 4), `config_loja.tema`/`cor_fundo`/`cor_texto` (Task 1)
- Produces: nothing new consumed by later tasks

- [ ] **Step 1: Read the current file, then replace its entire contents**

Replace the full contents of `config_sistema/aparencia.php` with:

```php
<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome_loja = trim($_POST['nome_loja'] ?? '');
    $tema = $_POST['tema'] ?? 'claro';
    $temasValidos = ['claro', 'escuro', 'personalizado'];
    $cor_primaria = trim($_POST['cor_primaria'] ?? '#8B5CF6');
    $cor_secundaria = trim($_POST['cor_secundaria'] ?? '#F472B6');
    $cor_fundo = trim($_POST['cor_fundo'] ?? '#FFFFFF');
    $cor_texto = trim($_POST['cor_texto'] ?? '#1F2937');

    if ($nome_loja === '' || !in_array($tema, $temasValidos, true)) {
        $erro = 'Informe o nome da loja e um tema válido.';
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
            if ($origem !== null && $origem !== false) {
                $dir = __DIR__ . '/../assets/img/logo/';
                if (!is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                imagepng($origem, $dir . 'logo.png', 9);
                imagedestroy($origem);
                $logoArquivo = 'assets/img/logo/logo.png';
            } else {
                $erro = 'Formato de logo inválido (use JPEG ou PNG).';
            }
        }

        if ($erro === '') {
            $campos = [
                ':nome' => $nome_loja,
                ':tema' => $tema,
                ':cp' => $cor_primaria,
                ':cs' => $cor_secundaria,
                ':cf' => $cor_fundo,
                ':ct' => $cor_texto,
            ];
            $sql = 'UPDATE config_loja SET nome_loja = :nome, tema = :tema, cor_primaria = :cp, cor_secundaria = :cs, cor_fundo = :cf, cor_texto = :ct';
            if ($logoArquivo !== null) {
                $sql .= ', logo_arquivo = :logo';
                $campos[':logo'] = $logoArquivo;
            }
            $sql .= ' WHERE id_config = 1';
            $pdo->prepare($sql)->execute($campos);
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
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Aparência da loja</h1>
    <?php if (isset($_GET['salvo'])): ?><p class="alert alert-sucesso">Configuração salva.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if (!empty($config['logo_arquivo'])): ?>
        <img src="/<?= htmlspecialchars($config['logo_arquivo']) ?>?v=<?= time() ?>" width="150" alt="Logo atual"><br>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <label>Nome da loja<br><input type="text" name="nome_loja" value="<?= htmlspecialchars($config['nome_loja']) ?>" required></label>
        <label>Logo (JPEG ou PNG)<br><input type="file" name="logo" accept="image/png,image/jpeg"></label>
        <label>Tema
            <select name="tema" id="tema">
                <option value="claro" <?= $config['tema'] === 'claro' ? 'selected' : '' ?>>Claro</option>
                <option value="escuro" <?= $config['tema'] === 'escuro' ? 'selected' : '' ?>>Escuro</option>
                <option value="personalizado" <?= $config['tema'] === 'personalizado' ? 'selected' : '' ?>>Personalizado</option>
            </select>
        </label>
        <div id="cores-personalizadas" style="<?= $config['tema'] !== 'personalizado' ? 'display:none;' : '' ?>">
            <label>Cor primária<br><input type="color" name="cor_primaria" value="<?= htmlspecialchars($config['cor_primaria']) ?>"></label>
            <label>Cor secundária<br><input type="color" name="cor_secundaria" value="<?= htmlspecialchars($config['cor_secundaria']) ?>"></label>
            <label>Cor de fundo<br><input type="color" name="cor_fundo" value="<?= htmlspecialchars($config['cor_fundo']) ?>"></label>
            <label>Cor do texto<br><input type="color" name="cor_texto" value="<?= htmlspecialchars($config['cor_texto']) ?>"></label>
        </div>
        <button type="submit">Salvar</button>
    </form>
<script>
document.getElementById('tema').addEventListener('change', function () {
    document.getElementById('cores-personalizadas').style.display = this.value === 'personalizado' ? '' : 'none';
});
</script>
</main>
</body>
</html>
```

- [ ] **Step 2: Verify manually**

Run: `C:\wamp64\bin\php\php8.5.0\php.exe -l config_sistema/aparencia.php` — expect no syntax errors.

Start a real local server, log in as Admin, visit `/config_sistema/aparencia.php` — confirm the shared header/nav renders, the theme select shows the current value, and the 4 color pickers are hidden unless "Personalizado" is selected (toggle the select and confirm the JS show/hide works). Save with `tema=escuro` — confirm it persists (`mysql -u root sistema_veronica -e "SELECT tema FROM config_loja"`). Upload a small JPEG as the logo — confirm it's saved at `assets/img/logo/logo.png` (not the old `assets/img/loja/`) and the page shows it afterward. Save with `tema=personalizado` and 4 custom hex colors — confirm all 4 persist correctly. Reset to `tema=claro` afterward if you want the rest of this plan's testing to start from the light theme.

- [ ] **Step 3: Commit**

```bash
git add config_sistema/aparencia.php
git commit -m "feat: add theme selector and logo path change to aparência screen"
```

---

### Task 6: Roll out header + CSS to the Loja Online (7 pages)

**Files:**
- Modify: `loja/index.php`, `loja/produto.php`, `loja/carrinho.php`, `loja/checkout.php`, `loja/cadastro.php`, `loja/minha_divida.php`, `loja/pedido_status.php`

**Interfaces:**
- Consumes: `includes/loja_header.php` (Task 4)
- Produces: nothing new consumed by later tasks

Every file in this task gets the **exact same two edits**. Every one of these 7 files currently has `<body>` (with nothing else on that line) as the very first thing inside the `<html>` tag, and `</body>` (with nothing else on that line, immediately followed by `</html>`) at the end — confirmed by reading all 7 files. For **each** of the 7 files listed above:

- [ ] **Step 1: Add the header include right after `<body>`**

Find (exact text, appears once per file):
```
<body>
```

Replace with:
```
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
```

- [ ] **Step 2: Close the `<main>` tag right before `</body>`**

Find (exact text, appears once per file):
```
</body>
```

Replace with:
```
</main>
</body>
```

- [ ] **Step 3: Run `php -l` on all 7 files**

```
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/index.php
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/produto.php
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/carrinho.php
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/checkout.php
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/cadastro.php
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/minha_divida.php
C:\wamp64\bin\php\php8.5.0\php.exe -l loja/pedido_status.php
```
Expected: no syntax errors on any of the 7.

- [ ] **Step 4: Verify manually — real page loads + mobile viewport**

Start a real local server. For **each** of the 7 pages, load it in a real browser (log in as a test client where the page requires it — `loja/carrinho.php`, `loja/checkout.php`, `loja/minha_divida.php`, and the logged-in view of `loja/produto.php` all need an authenticated client session; `loja/cadastro.php`, `loja/pedido_status.php`, and the logged-out view of `loja/index.php`/`loja/produto.php` can be checked without one):

1. Confirm the shared header/logo/nav renders at the top, with the correct nav links for the logged-in vs. logged-out state.
2. Confirm the existing page content (the page's own `<h1>`, forms, lists — whatever it already had) still renders correctly below the header, with no PHP errors/warnings.
3. Resize the browser to a mobile width (375px) and reload — confirm the header wraps/stacks instead of overflowing, and the page's own content (product grid on `index.php`, cart table on `carrinho.php`, forms on `cadastro.php`/`checkout.php`, extrato list on `minha_divida.php`) does not overflow horizontally or become unusable.
4. Exercise ONE real action per page to confirm nothing in the underlying business logic broke: add a product to the cart from `produto.php`, remove an item from `carrinho.php`, submit the WhatsApp step on `cadastro.php`, load `checkout.php` with items in the cart, load `minha_divida.php` for a client with a nonzero balance, load `pedido_status.php` for a real order id.

Write down, for each of the 7 pages: what it does, and the one action you exercised to confirm it still works — this becomes the "what changed and how to test it" summary for this batch.

- [ ] **Step 5: Commit**

```bash
git add loja/index.php loja/produto.php loja/carrinho.php loja/checkout.php loja/cadastro.php loja/minha_divida.php loja/pedido_status.php
git commit -m "feat: roll out shared header and stylesheet to the Loja Online"
```

---

### Task 7: Roll out header + CSS to internal pages, batch A (9 pages)

**Files:**
- Modify: `login.php` (special case — see Step 1a), `caixa/abertura.php`, `caixa/index.php`, `caixa/pagamento.php`, `caixa/comprovante.php`, `caixa/fechamento.php`, `clientes/lista.php`, `clientes/novo.php`, `clientes/detalhe.php`

**Interfaces:**
- Consumes: `includes/admin_header.php` (Task 4)
- Produces: nothing new consumed by later tasks

**`login.php` is a special case** — it renders before any staff session exists, so it must NOT include `includes/admin_header.php` (which reads `$_SESSION['nome']`/`$_SESSION['perfil']` and shows staff nav links that make no sense on a login screen). It gets only the CSS link and a `.container` wrapper, no header/nav.

- [ ] **Step 1a: `login.php` — CSS link only, no shared header**

Find (exact text, this file's `<head>` is multi-line, unlike the others):
```
<head>
    <meta charset="UTF-8">
    <title>Entrar — Sistema Veronica</title>
</head>
<body>
    <h1>Entrar</h1>
```

Replace with:
```
<head>
    <meta charset="UTF-8">
    <title>Entrar — Sistema Veronica</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<main class="container">
    <h1>Entrar</h1>
```

Find (exact text):
```
</body>
</html>
```

Replace with:
```
</main>
</body>
</html>
```

**All 8 remaining files in this batch** (`caixa/abertura.php`, `caixa/index.php`, `caixa/pagamento.php`, `caixa/comprovante.php`, `caixa/fechamento.php`, `clientes/lista.php`, `clientes/novo.php`, `clientes/detalhe.php`) get the same two edits as Task 6, using `admin_header.php` instead of `loja_header.php`. Each of these 8 files has `<body>` alone as the first line inside `<html>`, and `</body>` alone at the end — confirmed by reading all 8 files.

- [ ] **Step 1b: For each of the 8 files, add the header include right after `<body>`**

Find (exact text, appears once per file):
```
<body>
```

Replace with:
```
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
```

- [ ] **Step 1c: For each of the 8 files, close `<main>` right before `</body>`**

Find (exact text, appears once per file):
```
</body>
```

Replace with:
```
</main>
</body>
```

- [ ] **Step 2: Run `php -l` on all 9 files**

```
C:\wamp64\bin\php\php8.5.0\php.exe -l login.php
C:\wamp64\bin\php\php8.5.0\php.exe -l caixa/abertura.php
C:\wamp64\bin\php\php8.5.0\php.exe -l caixa/index.php
C:\wamp64\bin\php\php8.5.0\php.exe -l caixa/pagamento.php
C:\wamp64\bin\php\php8.5.0\php.exe -l caixa/comprovante.php
C:\wamp64\bin\php\php8.5.0\php.exe -l caixa/fechamento.php
C:\wamp64\bin\php\php8.5.0\php.exe -l clientes/lista.php
C:\wamp64\bin\php\php8.5.0\php.exe -l clientes/novo.php
C:\wamp64\bin\php\php8.5.0\php.exe -l clientes/detalhe.php
```
Expected: no syntax errors on any of the 9.

- [ ] **Step 3: Verify manually — real page loads + mobile viewport**

Start a real local server, log in as staff (both a Funcionário and an Admin session if you need to check `clientes/detalhe.php`'s Admin-only limit form still only shows for Admin). For **each** of the 9 pages:

1. Confirm `login.php` shows NO staff nav (correct — special case) but does show the styled form and container. Confirm the other 8 show the shared header/nav with the correct highlighted links, and correctly hide "Usuários"/"Aparência"/"Entrega" from a Funcionário session.
2. Confirm each page's existing functionality still renders below the header with no PHP errors — `caixa/abertura.php`'s open-caixa form, `caixa/index.php`'s product search/cart (open a caixa first if none is open), `caixa/pagamento.php`'s payment form (start a sale first), `caixa/comprovante.php` (load a real finished sale's receipt), `caixa/fechamento.php`'s closing summary, `clientes/lista.php`'s table, `clientes/novo.php`'s form, `clientes/detalhe.php`'s full credit-management UI (limit edit, payment registration, extrato).
3. Resize to 375px wide and reload each — confirm the header wraps, and every table (`clientes/lista.php`, `caixa/fechamento.php`'s operator summary) scrolls horizontally instead of breaking the page.
4. Exercise ONE real action per page where practical: log in via `login.php`, open a caixa via `abertura.php`, add a product to a sale via `index.php`, load the payment screen via `pagamento.php`, view a real receipt via `comprovante.php`, close the caixa via `fechamento.php`, list clients via `lista.php`, create a client via `novo.php`, view/edit a client's credit via `detalhe.php`.

Write down, for each of the 9 pages: what it does, and the one action you exercised — this becomes the "what changed and how to test it" summary for this batch.

- [ ] **Step 4: Commit**

```bash
git add login.php caixa/abertura.php caixa/index.php caixa/pagamento.php caixa/comprovante.php caixa/fechamento.php clientes/lista.php clientes/novo.php clientes/detalhe.php
git commit -m "feat: roll out shared header and stylesheet to internal pages (batch A)"
```

---

### Task 8: Roll out header + CSS to internal pages, batch B (8 pages)

**Files:**
- Modify: `produtos/lista.php`, `produtos/novo.php`, `produtos/editar.php`, `produtos/categorias.php`, `produtos/variacoes.php`, `usuarios/lista.php`, `usuarios/novo.php`, `config_sistema/entrega.php`

**Interfaces:**
- Consumes: `includes/admin_header.php` (Task 4)
- Produces: nothing new consumed by later tasks

All 8 files get the same two edits as Task 6/7, using `admin_header.php`. Each has `<body>` alone as the first line inside `<html>`, and `</body>` alone at the end — confirmed by reading all 8 files. Three of these files have a dynamic `<title>` (`produtos/variacoes.php` includes the category name) — this does not affect the `<body>`/`</body>` edits below, which are identical regardless of the title.

- [ ] **Step 1: For each of the 8 files, add the header include right after `<body>`**

Find (exact text, appears once per file):
```
<body>
```

Replace with:
```
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
```

- [ ] **Step 2: For each of the 8 files, close `<main>` right before `</body>`**

Find (exact text, appears once per file):
```
</body>
```

Replace with:
```
</main>
</body>
```

- [ ] **Step 3: Run `php -l` on all 8 files**

```
C:\wamp64\bin\php\php8.5.0\php.exe -l produtos/lista.php
C:\wamp64\bin\php\php8.5.0\php.exe -l produtos/novo.php
C:\wamp64\bin\php\php8.5.0\php.exe -l produtos/editar.php
C:\wamp64\bin\php\php8.5.0\php.exe -l produtos/categorias.php
C:\wamp64\bin\php\php8.5.0\php.exe -l produtos/variacoes.php
C:\wamp64\bin\php\php8.5.0\php.exe -l usuarios/lista.php
C:\wamp64\bin\php\php8.5.0\php.exe -l usuarios/novo.php
C:\wamp64\bin\php\php8.5.0\php.exe -l config_sistema/entrega.php
```
Expected: no syntax errors on any of the 8.

- [ ] **Step 4: Verify manually — real page loads + mobile viewport**

Start a real local server, log in as Admin (needed for `usuarios/*.php`, `config_sistema/entrega.php`). For **each** of the 8 pages:

1. Confirm the shared header/nav renders, with "Usuários"/"Aparência"/"Entrega" visible (Admin session).
2. Confirm each page's existing functionality still renders with no PHP errors — `produtos/lista.php`'s table, `produtos/novo.php`'s dynamic variation/combination form (this one has real inline JS driving the combinations UI — confirm that JS still works after the header change, since it manipulates the DOM below where the header now sits), `produtos/editar.php`'s edit form + photo upload/delete + the `display:flex` photo-thumbnails row (leave that inline style as-is, don't remove it), `produtos/categorias.php`'s create/delete flow, `produtos/variacoes.php`'s create/remove flow, `usuarios/lista.php`'s table, `usuarios/novo.php`'s form, `config_sistema/entrega.php`'s delivery-method management (leave its `display:inline` inline styles as-is too).
3. Resize to 375px wide and reload each — confirm the header wraps and every table (`produtos/lista.php`, `produtos/editar.php`'s combinations table, `produtos/categorias.php`, `produtos/variacoes.php`, `usuarios/lista.php`, `config_sistema/entrega.php`) scrolls horizontally instead of breaking the page.
4. Exercise ONE real action per page: list products, create a product with at least one variation combination, edit an existing product (change its name, confirm it saves), create/delete a category, create/remove a variation value, list users, create a user, toggle a delivery method's active state.

Write down, for each of the 8 pages: what it does, and the one action you exercised — this becomes the "what changed and how to test it" summary for this batch.

- [ ] **Step 5: Commit**

```bash
git add produtos/lista.php produtos/novo.php produtos/editar.php produtos/categorias.php produtos/variacoes.php usuarios/lista.php usuarios/novo.php config_sistema/entrega.php
git commit -m "feat: roll out shared header and stylesheet to internal pages (batch B)"
```

---

## Self-Review Notes

- **Spec coverage:** theme schema + engine (Task 1), mobile-first CSS for both areas (Tasks 2-3), shared navigation (Task 4), theme selector UI + new logo path (Task 5), all 24 UI pages connected (Tasks 6-8, 7+9+8=24 files, matching the spec's page list exactly). No spec requirement without a task.
- **No placeholders:** every CSS rule, every PHP file's full content or exact find/replace text, is given verbatim — the only thing NOT individually spelled out is the (byte-identical, confirmed-by-reading-all-24-files) `<body>`/`</body>` anchor text repeated across Tasks 6-8, which is correct because it genuinely is identical in every file, not because detail was skipped.
- **Cross-task interface consistency:** `imprimirVariaveisTema(PDO $pdo): void` (Task 1) is called with that exact signature in `includes/loja_header.php` (Task 4). The CSS class names `includes/loja_header.php`/`includes/admin_header.php` (Task 4) use (`.site-header`, `.site-header-inner`, `.site-logo`, `.site-nav`, `.site-nav-user`) are defined identically in both `assets/css/loja.css` and `assets/css/admin.css` (Tasks 2-3). Every rollout task (6-8) opens `<main class="container">` via the header include and closes it with `</main>` — verified the class name matches `.container` as defined in both stylesheets.
- **No business logic touched:** every rollout task's edit is confined to the `<body>`/`</body>` lines (pure presentation); Task 5 (`aparencia.php`) only adds new form fields and changes the logo save directory, it doesn't touch any Fundação/PDV/Loja Online/Linha de Crédito logic.
- **Mobile-first discipline:** both stylesheets (Tasks 2-3) write unprefixed rules for small screens first, with `@media (min-width: 768px)` as the only progressive-enhancement point — confirmed no `max-width` media query exists in either file (which would indicate a desktop-first approach written backwards).
