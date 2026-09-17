<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/config_dev.php';

$config = buscarConfigDev($pdo);
$nomeSistemaAtual = nomeDoSistema($pdo);
$mensagem = !empty($config['travamento_pagamento_mensagem'])
    ? $config['travamento_pagamento_mensagem']
    : 'Existe um pagamento em aberto para o seu sistema. Entre em contato com o suporte para regularizar a situação.';

$versaoCssAdmin = @filemtime(__DIR__ . '/assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sistema bloqueado — <?= htmlspecialchars($nomeSistemaAtual) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body>
<main class="container">
    <div class="auth-card" style="text-align:center;">
        <span class="icone-titulo" style="display:inline-flex; margin-bottom:14px; color:var(--cor-erro);">
            <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path d="M12 9v4m0 4h.01M10.3 3.9 2.5 17.5a1.5 1.5 0 0 0 1.3 2.25h16.4a1.5 1.5 0 0 0 1.3-2.25L13.7 3.9a1.5 1.5 0 0 0-2.6 0Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <h1><?= htmlspecialchars($nomeSistemaAtual) ?></h1>
        <p class="alert alert-erro" style="text-align:left;"><?= nl2br(htmlspecialchars($mensagem)) ?></p>
        <a href="/sair.php" class="btn-texto btn-sm" style="margin-top:10px;">Sair</a>
    </div>
</main>
</body>
</html>
