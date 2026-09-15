<?php
/**
 * Cabeçalho do painel_dev — visual propositalmente diferente do admin da
 * loja (fundo escuro, badge "DEV"), pra nunca dar pra confundir as duas
 * áreas. Espera $_SESSION já iniciado (includes/auth_dev.php faz isso) no
 * arquivo que inclui este.
 */
$versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
$versaoJsAdmin = @filemtime(__DIR__ . '/../assets/js/admin.js') ?: time();
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
<header class="site-header" style="background:#0F172A; border-bottom-color:#1E293B;">
    <div class="site-header-inner">
        <a href="/painel_dev/index.php" class="site-logo" style="color:#fff;">Painel do desenvolvedor</a>
        <button type="button" class="nav-toggle" id="nav-toggle" aria-expanded="false" aria-controls="site-nav" aria-label="Abrir menu">
            <span style="background:#fff;"></span><span style="background:#fff;"></span><span style="background:#fff;"></span>
        </button>
        <nav class="site-nav" id="site-nav" style="background:#0F172A;">
            <a href="/painel_dev/index.php" style="color:#fff;">Configurações</a>
            <a href="/painel_dev/usuarios.php" style="color:#fff;">Usuários da loja</a>
            <span class="site-nav-user" style="color:#94A3B8;">DevMaster</span>
            <a href="/painel_dev/sair.php" class="link-sair" style="color:#fff;">Sair</a>
        </nav>
    </div>
</header>
<script src="/assets/js/admin.js?v=<?= $versaoJsAdmin ?>" defer></script>
<main class="container">
