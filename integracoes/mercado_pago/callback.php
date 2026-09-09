<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/mp_client.php';

if (!isset($_GET['code']) || !isset($_GET['state'])) {
    header('Location: /integracoes/mercado_pago/conectar.php?erro=' . urlencode('Parâmetros ausentes na resposta do Mercado Pago.'));
    exit;
}

$code = $_GET['code'];
$nonceRecebido = $_GET['state'];

$config = mpConfig($pdo);
if (!$config || empty($config['mp_oauth_nonce']) || !hash_equals((string) $config['mp_oauth_nonce'], (string) $nonceRecebido)) {
    header('Location: /integracoes/mercado_pago/conectar.php?erro=' . urlencode('Falha de segurança (state inválido). Tente conectar novamente.'));
    exit;
}
if (empty($config['mp_oauth_nonce_expira']) || new DateTime($config['mp_oauth_nonce_expira']) < new DateTime()) {
    header('Location: /integracoes/mercado_pago/conectar.php?erro=' . urlencode('O link de conexão expirou. Tente novamente.'));
    exit;
}

$pdo->prepare('UPDATE config_pagamento SET mp_oauth_nonce = NULL, mp_oauth_nonce_expira = NULL WHERE id_config = 1')->execute();

$redirect_uri = 'https://brechodaveve.codernex.com.br/integracoes/mercado_pago/callback.php';

$ch = curl_init('https://api.mercadopago.com/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => MP_APP_CLIENT_ID,
        'client_secret' => MP_APP_CLIENT_SECRET,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirect_uri,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    CURLOPT_TIMEOUT => 15,
]);
$resposta = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($resposta === false || $http_code < 200 || $http_code >= 300) {
    header('Location: /integracoes/mercado_pago/conectar.php?erro=' . urlencode('Erro ao trocar código por token no Mercado Pago.'));
    exit;
}

$dados = json_decode($resposta, true);
if (!isset($dados['access_token'])) {
    header('Location: /integracoes/mercado_pago/conectar.php?erro=' . urlencode('Resposta inesperada do Mercado Pago.'));
    exit;
}

$expira = isset($dados['expires_in']) ? date('Y-m-d H:i:s', time() + (int) $dados['expires_in']) : null;

$pdo->prepare('UPDATE config_pagamento SET mp_access_token = :at, mp_refresh_token = :rt, mp_public_key = :pk, mp_user_id = :uid, mp_token_expira = :exp WHERE id_config = 1')
    ->execute([
        ':at' => $dados['access_token'],
        ':rt' => $dados['refresh_token'] ?? null,
        ':pk' => $dados['public_key'] ?? null,
        ':uid' => $dados['user_id'] ?? null,
        ':exp' => $expira,
    ]);

header('Location: /integracoes/mercado_pago/conectar.php?conectado=1');
exit;
