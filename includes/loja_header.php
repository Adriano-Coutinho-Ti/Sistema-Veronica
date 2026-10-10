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
<?php
// Cache-busting: a versão na URL é o horário de modificação do próprio arquivo,
// então toda vez que loja.css/loja.js é atualizado no servidor a URL muda
// sozinha e o navegador busca a versão nova — nunca precisa lembrar de trocar
// um número de versão manualmente.
$versaoCss = @filemtime(__DIR__ . '/../assets/css/loja.css') ?: time();
$versaoJs = @filemtime(__DIR__ . '/../assets/js/loja.js') ?: time();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="/assets/css/loja.css?v=<?= $versaoCss ?>">
<?php imprimirVariaveisTema($pdo); ?>
<?php require_once __DIR__ . '/pwa.php'; pwaTags($pdo, 'loja'); ?>
<header class="site-header">
    <div class="site-header-inner">
        <a href="/loja/index.php" class="site-logo">
            <?php if (!empty($configLoja['logo_arquivo'])): ?>
                <img src="/<?= htmlspecialchars($configLoja['logo_arquivo']) ?>" alt="<?= htmlspecialchars($configLoja['nome_loja']) ?>">
            <?php endif; ?>
            <?= htmlspecialchars($configLoja['nome_loja']) ?>
        </a>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Abrir menu">
            <span></span><span></span><span></span>
        </button>
        <nav class="site-nav" id="site-nav">
            <a href="/loja/index.php">Catálogo</a>
            <?php if (!empty($_SESSION['id_cliente'])): ?>
                <a href="/loja/carrinho.php">Carrinho</a>
                <a href="/loja/favoritos.php">Favoritos</a>
                <a href="/loja/meus_pedidos.php">Meus pedidos</a>
                <a href="/loja/minha_divida.php">Meus débitos</a>
                <a href="/loja/minha_conta.php" class="site-nav-user">Olá, <?= htmlspecialchars($_SESSION['nome_cliente']) ?></a>
                <a href="/loja/logout.php" class="link-sair" id="link-sair">Sair</a>
            <?php else: ?>
                <a href="/loja/cadastro.php">Entrar / Cadastrar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if (!empty($_SESSION['id_cliente'])): ?>
<div class="modal-overlay" id="modal-sair" hidden>
    <div class="modal-card">
        <h3>Sair da conta</h3>
        <p>Tem certeza que quer sair da sua conta?</p>
        <div class="modal-acoes">
            <button type="button" class="btn-outline" id="modal-sair-cancelar">Cancelar</button>
            <a href="/loja/logout.php" class="btn">Sim, sair</a>
        </div>
    </div>
</div>

<div class="modal-overlay" id="modal-validar-email" hidden>
    <div class="modal-card">
        <h3>Validar e-mail</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Enviamos um código de 6 dígitos pro seu e-mail. Digite ele abaixo pra confirmar.</p>
        <p class="alert alert-erro" id="msg-erro-validar-email" hidden></p>
        <form id="form-validar-email">
            <label>Código<input type="text" id="campo-codigo-email" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code" required></label>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-fechar-validar-email">Fechar</button>
                <button type="submit" class="btn">Validar</button>
            </div>
        </form>
        <p style="text-align:center; margin-top:14px;">
            <button type="button" class="btn-texto" id="btn-nao-recebi-email" style="padding:0;">Não recebi o e-mail, reenviar código</button>
        </p>
    </div>
</div>

<div class="modal-overlay" id="modal-validar-whatsapp" hidden>
    <div class="modal-card">
        <h3>Validar WhatsApp</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Enviamos um código de 6 dígitos pro seu WhatsApp. Digite ele abaixo pra confirmar.</p>
        <p class="alert alert-erro" id="msg-erro-validar-whatsapp" hidden></p>
        <form id="form-validar-whatsapp">
            <label>Código<input type="text" id="campo-codigo-whatsapp" inputmode="numeric" maxlength="6" placeholder="000000" autocomplete="one-time-code" required></label>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-fechar-validar-whatsapp">Fechar</button>
                <button type="submit" class="btn">Validar</button>
            </div>
        </form>
        <p style="text-align:center; margin-top:14px;">
            <button type="button" class="btn-texto" id="btn-nao-recebi-whatsapp" style="padding:0;">Não recebi no WhatsApp, reenviar código</button>
        </p>
    </div>
</div>
<?php endif; ?>

<script src="/assets/js/loja.js?v=<?= $versaoJs ?>" defer></script>
<main class="container">
