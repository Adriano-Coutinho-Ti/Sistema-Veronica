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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
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
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Abrir menu">
            <span></span><span></span><span></span>
        </button>
        <nav class="site-nav" id="site-nav">
            <a href="/loja/index.php">Catálogo</a>
            <?php if (!empty($_SESSION['id_cliente'])): ?>
                <a href="/loja/carrinho.php">Carrinho</a>
                <a href="/loja/meus_pedidos.php">Meus pedidos</a>
                <a href="/loja/minha_divida.php">Meus débitos</a>
                <span class="site-nav-user">Olá, <?= htmlspecialchars($_SESSION['nome_cliente']) ?></span>
                <a href="/loja/logout.php">Sair</a>
            <?php else: ?>
                <a href="/loja/cadastro.php">Entrar / Cadastrar</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<script src="/assets/js/loja.js" defer></script>
<main class="container">
