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
<?php
require_once __DIR__ . '/config_dev.php';
$versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
$versaoJsAdmin = @filemtime(__DIR__ . '/../assets/js/admin.js') ?: time();
$nomeSistemaAtual = nomeDoSistema($pdo);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
<header class="site-header">
    <div class="site-header-inner">
        <a href="/dashboard.php" class="site-logo"><?= htmlspecialchars($nomeSistemaAtual) ?></a>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Abrir menu">
            <span></span><span></span><span></span>
        </button>
        <nav class="site-nav" id="site-nav">
            <a href="/dashboard.php">Dashboard</a>
            <div class="nav-grupo">
                <button type="button" class="nav-grupo-trigger">Produtos</button>
                <div class="nav-submenu">
                    <a href="/produtos/lista.php">Todos os produtos</a>
                    <a href="/produtos/categorias.php">Categorias</a>
                </div>
            </div>
            <div class="nav-grupo">
                <button type="button" class="nav-grupo-trigger">Clientes</button>
                <div class="nav-submenu">
                    <a href="/clientes/lista.php">Todos os clientes</a>
                    <a href="/clientes/devedores.php">Devedores</a>
                    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
                    <a href="/clientes/solicitacoes_credito.php">Solicitações de crédito</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="nav-grupo">
                <button type="button" class="nav-grupo-trigger">Caixa</button>
                <div class="nav-submenu">
                    <a href="/caixa/index.php">PDV</a>
                    <a href="/caixa/vendas.php">Vendas do caixa</a>
                    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
                    <a href="/caixa/historico.php">Histórico de caixas</a>
                    <?php endif; ?>
                </div>
            </div>
            <a href="/pedidos/lista.php">Pedidos</a>
            <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
            <div class="nav-grupo">
                <button type="button" class="nav-grupo-trigger">Configurações</button>
                <div class="nav-submenu">
                    <a href="/usuarios/lista.php">Usuários</a>
                    <a href="/config_sistema/aparencia.php">Aparência</a>
                    <a href="/config_sistema/entrega.php">Entrega</a>
                    <a href="/config_sistema/pdv.php">PDV</a>
                    <a href="/integracoes/mercado_pago/conectar.php">Mercado Pago</a>
                </div>
            </div>
            <?php endif; ?>
            <span class="site-nav-user">Olá, <?= htmlspecialchars($_SESSION['nome'] ?? '') ?></span>
            <a href="/sair.php">Sair</a>
        </nav>
    </div>
</header>
<script src="/assets/js/admin.js?v=<?= $versaoJsAdmin ?>" defer></script>
<main class="container">
