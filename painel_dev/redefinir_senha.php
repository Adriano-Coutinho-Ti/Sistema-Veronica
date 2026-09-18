<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/dev_seguranca.php';

$token = trim($_POST['token'] ?? $_GET['token'] ?? '');
$dev = $token !== '' ? buscarDevPorTokenValido($pdo, $token) : null;

$erro = '';
$sucesso = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$dev) {
        $erro = 'Esse link já expirou ou não é mais válido — peça um novo, tentando entrar de novo no painel.';
    } else {
        $novaSenha = $_POST['nova_senha'] ?? '';
        $confirmarSenha = $_POST['confirmar_senha'] ?? '';

        if (strlen($novaSenha) < 6) {
            $erro = 'A nova senha precisa ter pelo menos 6 caracteres.';
        } elseif ($novaSenha !== $confirmarSenha) {
            $erro = 'A confirmação não bate com a nova senha.';
        } else {
            $pdo->prepare('UPDATE dev_usuarios SET senha_hash = :senha WHERE id_dev_usuario = :id')
                ->execute([':senha' => password_hash($novaSenha, PASSWORD_DEFAULT), ':id' => $dev['id_dev_usuario']]);
            limparTentativasLogin($pdo, (int) $dev['id_dev_usuario']);
            $sucesso = true;
        }
    }
}

$versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Redefinir senha — Painel do desenvolvedor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body style="background:#0F172A;">
<main class="container">
    <div class="auth-card" style="max-width:440px;">
        <p style="text-align:center; text-transform:uppercase; letter-spacing:0.08em; font-size:0.75rem; font-weight:700; color:var(--cor-primaria); margin-bottom:6px;">Área restrita</p>
        <h1>Redefinir senha</h1>

        <?php if ($sucesso): ?>
            <p class="alert alert-sucesso">Senha redefinida com sucesso. Já pode entrar normalmente.</p>
            <p style="text-align:center; margin-top:14px;"><a href="/painel_dev/login.php">Ir para o login</a></p>
        <?php elseif (!$dev): ?>
            <p class="alert alert-erro">Esse link já expirou ou não é mais válido. Tente entrar de novo no painel — se as tentativas incorretas continuarem, um novo link é enviado pro seu e-mail.</p>
            <p style="text-align:center; margin-top:14px;"><a href="/painel_dev/login.php">Ir para o login</a></p>
        <?php else: ?>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Definindo uma nova senha pra <?= htmlspecialchars($dev['nome'] ?: $dev['usuario']) ?>.</p>
            <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <label>Nova senha<input type="password" name="nova_senha" minlength="6" required autofocus></label>
                <label>Confirmar nova senha<input type="password" name="confirmar_senha" minlength="6" required></label>
                <button type="submit" class="btn-bloco">Redefinir senha</button>
            </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
