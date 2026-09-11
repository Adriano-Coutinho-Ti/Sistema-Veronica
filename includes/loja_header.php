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
