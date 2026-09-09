<?php

function mpConfig(PDO $pdo): ?array
{
    $stmt = $pdo->query('SELECT * FROM config_pagamento WHERE id_config = 1');
    return $stmt->fetch() ?: null;
}

/**
 * Chamada genérica à API do Mercado Pago. $metodo é 'GET' ou 'POST'.
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
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $resposta = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        throw new Exception('Erro de conexão com o Mercado Pago: ' . $erro);
    }

    return ['http_code' => $http_code, 'dados' => json_decode($resposta, true)];
}
