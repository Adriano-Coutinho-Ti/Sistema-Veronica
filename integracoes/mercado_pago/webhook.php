<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/credito.php';
require_once __DIR__ . '/../../includes/mp_client.php';

/**
 * Endpoint único de webhook — é o que deve ser cadastrado no aplicativo do
 * Mercado Pago (o painel deles só aceita uma URL de webhook por aplicativo,
 * não uma por fluxo). Atende os 3 fluxos de pagamento do sistema (loja/
 * checkout, PDV/caixa, dívida) decidindo qual pelo prefixo do
 * external_reference de cada venda/movimento (loja_/venda_/divida_), que já
 * existia pra esse fim.
 */

// Sempre responde 200 pro Mercado Pago não ficar re-tentando indefinidamente —
// erros são só registrados em log, nunca retornados como HTTP de erro aqui.
http_response_code(200);
header('Content-Type: application/json');

// PHP reescreve pontos em nomes de parâmetro GET/POST para underscore — a URL de
// notificação do Mercado Pago manda "?data.id=<id>", que o PHP entrega como
// $_GET['data_id']. O formato mais antigo (IPN) manda só "?id=<id>", daí o fallback.
$dataId = $_GET['data_id'] ?? $_GET['id'] ?? null;

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

$webhookSecret = mpAppCredenciais($pdo)['webhook_secret'];
if (!$dataId || !$xSignature || !$webhookSecret) {
    echo json_encode(['received' => true]);
    exit;
}

if (!mpValidarAssinaturaWebhook($xSignature, $xRequestId, strtolower((string) $dataId), $webhookSecret)) {
    error_log('Webhook MP: assinatura inválida para data.id=' . $dataId);
    echo json_encode(['received' => true]);
    exit;
}

try {
    $config = mpConfig($pdo);
    if (!$config || empty($config['mp_access_token'])) {
        echo json_encode(['received' => true]);
        exit;
    }

    $resposta = mpChamarApi('GET', 'https://api.mercadopago.com/v1/payments/' . $dataId, null, $config['mp_access_token']);
    $pagamento = $resposta['dados'] ?? [];
    $externalRef = $pagamento['external_reference'] ?? '';

    if (str_starts_with($externalRef, 'loja_')) {
        processarWebhookVendaLoja($pdo, (int) substr($externalRef, strlen('loja_')), $pagamento, (string) $dataId);
    } elseif (str_starts_with($externalRef, 'venda_')) {
        processarWebhookVendaCaixa($pdo, (int) substr($externalRef, strlen('venda_')), $pagamento, (string) $dataId);
    } elseif (str_starts_with($externalRef, 'divida_')) {
        processarWebhookDivida($pdo, (int) substr($externalRef, strlen('divida_')), $pagamento, (string) $dataId);
    }
} catch (Throwable $e) {
    error_log('Webhook MP erro: ' . $e->getMessage());
}

echo json_encode(['received' => true]);
