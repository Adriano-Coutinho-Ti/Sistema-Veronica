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

    $redirect_uri = urlBaseAtual() . '/integracoes/mercado_pago/callback.php';
    $auth_url = 'https://auth.mercadopago.com/authorization?' . http_build_query([
        'client_id' => mpAppCredenciais($pdo)['client_id'],
        'response_type' => 'code',
        'state' => $nonce,
        'redirect_uri' => $redirect_uri,
    ]);
    header('Location: ' . $auth_url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'desconectar') {
    $pdo->prepare(
        'UPDATE config_pagamento SET mp_access_token = NULL, mp_refresh_token = NULL, mp_public_key = NULL, mp_user_id = NULL, mp_token_expira = NULL WHERE id_config = 1'
    )->execute();
    header('Location: /integracoes/mercado_pago/conectar.php?desconectado=1');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Conectar Mercado Pago</title></head>
<body>
<?php require __DIR__ . '/../../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2 10h20" stroke="currentColor" stroke-width="1.8"/></svg></span>
        <div>
            <h1>Mercado Pago</h1>
            <span class="subtitulo">Recebimento de pagamentos via Pix e cartão</span>
        </div>
    </div>
    <?php if (isset($_GET['conectado'])): ?><p class="alert alert-sucesso">Conectado com sucesso!</p><?php endif; ?>
    <?php if (isset($_GET['desconectado'])): ?><p class="alert alert-sucesso">Conta desconectada.</p><?php endif; ?>
    <?php if (isset($_GET['erro'])): ?><p class="alert alert-erro"><?= htmlspecialchars($_GET['erro']) ?></p><?php endif; ?>
    <div class="card" style="max-width:480px;">
    <p><span class="status-pill<?= $conectado ? ' sucesso' : '' ?>"><?= $conectado ? 'Conectado (usuário MP #' . htmlspecialchars((string) $config['mp_user_id']) . ')' : 'Não conectado' ?></span></p>
    <form method="post">
        <input type="hidden" name="acao" value="iniciar">
        <button type="submit" class="btn-bloco"><?= $conectado ? 'Reconectar' : 'Conectar' ?> minha conta Mercado Pago</button>
    </form>
    <?php if ($conectado): ?>
    <form method="post" data-confirm="Remover a conexão com o Mercado Pago? A loja online e o PDV não vão conseguir receber pagamentos via Pix/cartão até reconectar." style="margin-top:10px;">
        <input type="hidden" name="acao" value="desconectar">
        <button type="submit" class="btn-bloco btn-perigo">Remover conta conectada</button>
    </form>
    <?php endif; ?>
    </div>
</main>
</body>
</html>
