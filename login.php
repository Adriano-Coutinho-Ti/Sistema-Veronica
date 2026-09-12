<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/auth.php';

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
        header('Location: /produtos/lista.php');
        exit;
    }

    $erro = 'E-mail ou senha inválidos.';
}

$versaoCssAdmin = @filemtime(__DIR__ . '/assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar — Sistema Veronica</title>
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body>
<main class="container">
    <h1>Entrar</h1>
    <?php if ($erro): ?>
        <p style="color:red;"><?= htmlspecialchars($erro) ?></p>
    <?php endif; ?>
    <form method="post">
        <label>E-mail<br><input type="email" name="email" required></label><br>
        <label>Senha<br><input type="password" name="senha" required></label><br>
        <button type="submit">Entrar</button>
    </form>
</main>
</body>
</html>
