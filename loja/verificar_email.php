<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';

$token = $_GET['token'] ?? '';
$sucesso = false;
$mensagem = '';

if ($token === '') {
    $mensagem = 'Link inválido.';
} else {
    $stmt = $pdo->prepare('SELECT id_cliente FROM clientes WHERE token_verificacao_email = :t AND token_verificacao_expira_em > NOW()');
    $stmt->execute([':t' => $token]);
    $cliente = $stmt->fetch();

    if ($cliente) {
        $pdo->prepare('UPDATE clientes SET email_verificado_em = NOW(), token_verificacao_email = NULL, token_verificacao_expira_em = NULL WHERE id_cliente = :id')
            ->execute([':id' => $cliente['id_cliente']]);
        $sucesso = true;
    } else {
        $mensagem = 'Esse link é inválido ou já expirou.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Confirmar e-mail</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="auth-card">
    <?php if ($sucesso): ?>
        <h1>E-mail confirmado!</h1>
        <p style="text-align:center; color:var(--cor-texto-suave); margin-bottom:20px;">Agora você já pode usar o carrinho normalmente.</p>
        <a href="/loja/index.php" class="btn btn-bloco">Ir para o catálogo</a>
    <?php else: ?>
        <h1>Não foi possível confirmar</h1>
        <p class="alert alert-erro"><?= htmlspecialchars($mensagem) ?></p>
        <?php if (!empty($_SESSION['id_cliente'])): ?>
        <p style="text-align:center;">Peça um novo link em <a href="/loja/minha_conta.php">Minha conta</a>.</p>
        <?php else: ?>
        <a href="/loja/cadastro.php" class="btn btn-bloco">Entrar na loja</a>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
