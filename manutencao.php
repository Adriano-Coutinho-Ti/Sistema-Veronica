<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/config_dev.php';

$config = buscarConfigDev($pdo);
$nomeSistemaAtual = nomeDoSistema($pdo);
$mensagem = !empty($config['travamento_manutencao_mensagem'])
    ? $config['travamento_manutencao_mensagem']
    : 'O sistema está em manutenção no momento. Voltamos em breve.';

$versaoCssAdmin = @filemtime(__DIR__ . '/assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Em manutenção — <?= htmlspecialchars($nomeSistemaAtual) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body>
<main class="container">
    <div class="auth-card" style="text-align:center;">
        <span class="icone-titulo" style="display:inline-flex; margin-bottom:14px;">
            <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 1 1.4 0l1.6 1.6a1 1 0 0 1 0 1.4l-8 8a1 1 0 0 1-.5.27l-3 .7a.5.5 0 0 1-.6-.6l.7-3a1 1 0 0 1 .27-.5l8-8ZM4 21h16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <h1><?= htmlspecialchars($nomeSistemaAtual) ?></h1>
        <p style="color:var(--cor-texto-suave);"><?= nl2br(htmlspecialchars($mensagem)) ?></p>
    </div>
</main>
</body>
</html>
