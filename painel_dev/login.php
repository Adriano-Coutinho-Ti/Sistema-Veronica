<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';

if (!empty($_SESSION['dev_usuario_id'])) {
    header('Location: /painel_dev/index.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim($_POST['usuario'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $pdo->prepare('SELECT id_dev_usuario, senha_hash FROM dev_usuarios WHERE usuario = :usuario');
    $stmt->execute([':usuario' => $usuario]);
    $dev = $stmt->fetch();

    if ($dev && password_verify($senha, $dev['senha_hash'])) {
        $_SESSION['dev_usuario_id'] = $dev['id_dev_usuario'];
        $_SESSION['dev_usuario_nome'] = $usuario;
        session_regenerate_id(true);
        header('Location: /painel_dev/index.php');
        exit;
    }

    $erro = 'Usuário ou senha incorretos.';
}

$versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel do desenvolvedor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body style="background:#0F172A;">
<main class="container">
    <div class="auth-card">
        <p style="text-align:center; text-transform:uppercase; letter-spacing:0.08em; font-size:0.75rem; font-weight:700; color:var(--cor-primaria); margin-bottom:6px;">Área restrita</p>
        <h1>Painel do desenvolvedor</h1>
        <?php if ($erro): ?>
            <p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>Usuário<input type="text" name="usuario" autocomplete="off" required autofocus></label>
            <label>Senha<input type="password" name="senha" required></label>
            <button type="submit" class="btn-bloco">Entrar</button>
        </form>
    </div>
</main>
</body>
</html>
