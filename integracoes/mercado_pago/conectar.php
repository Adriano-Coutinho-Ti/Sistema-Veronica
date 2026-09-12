<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirAdmin();

$config = mpConfig($pdo);
$conectado = !empty($config['mp_access_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'iniciar') {
    $nonce = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE config_pagamento SET mp_oauth_nonce = :nonce, mp_oauth_nonce_expira = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id_config = 1')
        ->execute([':nonce' => $nonce]);

    $redirect_uri = 'https://brechodaveve.codernex.com.br/integracoes/mercado_pago/callback.php';
    $auth_url = 'https://auth.mercadopago.com/authorization?' . http_build_query([
        'client_id' => MP_APP_CLIENT_ID,
        'response_type' => 'code',
        'state' => $nonce,
        'redirect_uri' => $redirect_uri,
    ]);
    header('Location: ' . $auth_url);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Conectar Mercado Pago</title></head>
<body>
<?php require __DIR__ . '/../../includes/admin_header.php'; ?>
    <h1>Mercado Pago</h1>
    <?php if (isset($_GET['conectado'])): ?><p class="alert alert-sucesso">Conectado com sucesso!</p><?php endif; ?>
    <?php if (isset($_GET['erro'])): ?><p class="alert alert-erro"><?= htmlspecialchars($_GET['erro']) ?></p><?php endif; ?>
    <div class="card" style="max-width:480px;">
    <p><span class="status-pill<?= $conectado ? ' sucesso' : '' ?>"><?= $conectado ? 'Conectado (usuário MP #' . htmlspecialchars((string) $config['mp_user_id']) . ')' : 'Não conectado' ?></span></p>
    <form method="post">
        <input type="hidden" name="acao" value="iniciar">
        <button type="submit" class="btn-bloco"><?= $conectado ? 'Reconectar' : 'Conectar' ?> minha conta Mercado Pago</button>
    </form>
    </div>
</main>
</body>
</html>
