<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config_dev.php';

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    $stmt = $pdo->prepare('SELECT id_usuario, nome, senha_hash, perfil FROM usuarios WHERE email = :email AND ativo = 1');
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if ($usuario && password_verify($senha, $usuario['senha_hash'])) {
        $_SESSION['id_usuario'] = $usuario['id_usuario'];
        $_SESSION['nome'] = $usuario['nome'];
        $_SESSION['perfil'] = $usuario['perfil'];
        session_regenerate_id(true);
        header('Location: /dashboard.php');
        exit;
    }

    $erro = 'E-mail ou senha inválidos.';
}

$versaoCssAdmin = @filemtime(__DIR__ . '/assets/css/admin.css') ?: time();
$nomeSistemaAtual = nomeDoSistema($pdo);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar — <?= htmlspecialchars($nomeSistemaAtual) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
    <?php require_once __DIR__ . '/includes/pwa.php'; pwaTags($pdo, 'admin'); ?>
</head>
<body>
<main class="container">
    <div class="auth-card">
        <h1><?= htmlspecialchars($nomeSistemaAtual) ?></h1>
        <?php if ($erro): ?>
            <p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p>
        <?php endif; ?>
        <form method="post">
            <label>E-mail<input type="email" name="email" required></label>
            <label>Senha<input type="password" name="senha" required></label>
            <button type="submit" class="btn-bloco">Entrar</button>
        </form>
    </div>
</main>
</body>
</html>
