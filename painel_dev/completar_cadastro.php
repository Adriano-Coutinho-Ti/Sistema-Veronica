<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';
exigirDev();

$id_dev_usuario = (int) $_SESSION['dev_usuario_id'];
$stmt = $pdo->prepare('SELECT id_dev_usuario, usuario, nome, email FROM dev_usuarios WHERE id_dev_usuario = :id');
$stmt->execute([':id' => $id_dev_usuario]);
$dev = $stmt->fetch();

if (!$dev) {
    header('Location: /painel_dev/login.php');
    exit;
}

if (!empty($dev['nome']) && !empty($dev['email'])) {
    header('Location: /painel_dev/index.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirmarSenha = $_POST['confirmar_senha'] ?? '';

    if ($nome === '') {
        $erro = 'Informe seu nome.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido — ele não é usado pra login, só pra você receber alertas de segurança e o link de redefinição de senha se precisar.';
    } elseif (strlen($novaSenha) < 6) {
        $erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
    } elseif ($novaSenha !== $confirmarSenha) {
        $erro = 'A confirmação não bate com a nova senha.';
    } else {
        $pdo->prepare('UPDATE dev_usuarios SET nome = :nome, email = :email, senha_hash = :senha WHERE id_dev_usuario = :id')
            ->execute([
                ':nome' => $nome,
                ':email' => $email,
                ':senha' => password_hash($novaSenha, PASSWORD_DEFAULT),
                ':id' => $id_dev_usuario,
            ]);
        header('Location: /painel_dev/index.php');
        exit;
    }
}

$versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Complete seu cadastro — Painel do desenvolvedor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body style="background:#0F172A;">
<main class="container">
    <div class="auth-card" style="max-width:440px;">
        <p style="text-align:center; text-transform:uppercase; letter-spacing:0.08em; font-size:0.75rem; font-weight:700; color:var(--cor-primaria); margin-bottom:6px;">Primeiro acesso</p>
        <h1>Complete seu cadastro</h1>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">
            Antes de continuar, confirme seu nome, um e-mail (usado só pra alertas de segurança e recuperação de conta — nunca pra login) e defina uma senha nova.
        </p>
        <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
        <form method="post">
            <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($_POST['nome'] ?? $dev['nome'] ?? '') ?>" required autofocus></label>
            <label>E-mail<input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? $dev['email'] ?? '') ?>" required></label>
            <label>Nova senha<input type="password" name="nova_senha" minlength="6" required></label>
            <label>Confirmar nova senha<input type="password" name="confirmar_senha" minlength="6" required></label>
            <button type="submit" class="btn-bloco">Salvar e continuar</button>
        </form>
    </div>
</main>
</body>
</html>
