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
<?php $versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time(); ?>
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
<header class="site-header">
    <div class="site-header-inner">
        <a href="/produtos/lista.php" class="site-logo">Sistema Veronica</a>
        <nav class="site-nav">
            <a href="/produtos/lista.php">Produtos</a>
            <a href="/produtos/categorias.php">Categorias</a>
            <a href="/clientes/lista.php">Clientes</a>
            <a href="/caixa/index.php">Caixa</a>
            <a href="/pedidos/lista.php">Pedidos</a>
            <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
                <a href="/caixa/historico.php">Histórico de Caixas</a>
                <a href="/usuarios/lista.php">Usuários</a>
                <a href="/config_sistema/aparencia.php">Aparência</a>
                <a href="/config_sistema/entrega.php">Entrega</a>
                <a href="/integracoes/mercado_pago/conectar.php">Mercado Pago</a>
            <?php endif; ?>
            <span class="site-nav-user">Olá, <?= htmlspecialchars($_SESSION['nome'] ?? '') ?></span>
            <a href="/sair.php">Sair</a>
        </nav>
    </div>
</header>
<main class="container">
