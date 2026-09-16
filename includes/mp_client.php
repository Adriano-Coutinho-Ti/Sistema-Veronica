<?php

function mpConfig(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT * FROM config_pagamento WHERE id_config = 1');
    return $stmt->fetch() ?: null;
}

/**
 * Credenciais do APLICATIVO Mercado Pago (a integração OAuth da própria
 * CoderNex, não o token de cada loja) — vêm do painel_dev (config_dev) se o
 * desenvolvedor já preencheu por lá, senão caem nas constantes MP_APP_* /
 * MP_WEBHOOK_SECRET definidas em brechodaveve_config_credenciais.php, do
 * jeito que sempre funcionou. Isso deixa o painel opcional: nada quebra pra
 * quem nunca abriu o painel_dev.
 */
function mpAppCredenciais(PDO $pdo): array
{
    $configDev = $pdo->query('SELECT mp_app_client_id, mp_app_client_secret, mp_webhook_secret FROM config_dev WHERE id_config = 1')->fetch();

    return [
        'client_id' => $configDev['mp_app_client_id'] ?? null ?: (defined('MP_APP_CLIENT_ID') ? MP_APP_CLIENT_ID : null),
        'client_secret' => $configDev['mp_app_client_secret'] ?? null ?: (defined('MP_APP_CLIENT_SECRET') ? MP_APP_CLIENT_SECRET : null),
        'webhook_secret' => $configDev['mp_webhook_secret'] ?? null ?: (defined('MP_WEBHOOK_SECRET') ? MP_WEBHOOK_SECRET : null),
    ];
}

/**
 * Percentual da taxa de marketplace cobrada em toda venda via Mercado Pago
 * (loja, PDV e pagamento de dívida) — configurável pelo painel_dev. Retorna
 * como percentual (1.5 = 1,5%), não como fração.
 */
function mpTaxaMarketplace(PDO $pdo): float
{
    $taxa = $pdo->query('SELECT marketplace_fee_percentual FROM config_dev WHERE id_config = 1')->fetchColumn();
    return $taxa !== false && $taxa !== null ? (float) $taxa : 1.0;
}

/**
 * Chamada genérica à API do Mercado Pago. $metodo é 'GET', 'POST' ou 'PUT'.
 */
function mpChamarApi(string $metodo, string $url, ?array $payload, string $access_token, array $headersExtra = []): array
{
    $headers = array_merge([
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token,
    ], $headersExtra);

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    } elseif ($metodo === 'PUT') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $resposta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);

    if ($resposta === false) {
        throw new Exception('Erro de conexão com o Mercado Pago: ' . $erro);
    }

    return ['http_code' => $http_code, 'dados' => json_decode($resposta, true)];
}

/**
 * Valida a assinatura X-Signature do webhook do Mercado Pago.
 * Formato do header: "ts=<timestamp>,v1=<hash>"
 * Manifesto: "id:{dataId};request-id:{xRequestId};ts:{ts};" (dataId em minúsculas)
 */
function mpValidarAssinaturaWebhook(string $xSignature, string $xRequestId, string $dataId, string $secret): bool
{
    $partes = [];
    foreach (explode(',', $xSignature) as $par) {
        $dividido = explode('=', trim($par), 2);
        if (count($dividido) === 2) {
            $partes[trim($dividido[0])] = trim($dividido[1]);
        }
    }

    $ts = $partes['ts'] ?? null;
    $v1 = $partes['v1'] ?? null;
    if (!$ts || !$v1) {
        return false;
    }

    $manifesto = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
    $hash = hash_hmac('sha256', $manifesto, $secret);

    return hash_equals($hash, $v1);
}
