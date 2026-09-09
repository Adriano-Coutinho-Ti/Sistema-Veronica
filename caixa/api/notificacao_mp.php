<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/mp_client.php';

// Sempre responde 200 pro Mercado Pago não ficar re-tentando indefinidamente —
// erros são só registrados em log, nunca retornados como HTTP de erro aqui.
http_response_code(200);
header('Content-Type: application/json');

// PHP reescreve pontos em nomes de parâmetro GET/POST para underscore — a URL de
// notificação do Mercado Pago manda "?data.id=<id>", que o PHP entrega como
// $_GET['data_id'], nunca como $_GET['data']['id'] (sintaxe de array, o MP não manda
// assim) nem como $_GET['data.id'] (ponto literal, impossível de receber). O formato
// mais antigo (IPN) manda só "?id=<id>", por isso o fallback.
$dataId = $_GET['data_id'] ?? $_GET['id'] ?? null;

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

if (!$dataId || !$xSignature || !defined('MP_WEBHOOK_SECRET')) {
    echo json_encode(['received' => true]);
    exit;
}

if (!mpValidarAssinaturaWebhook($xSignature, $xRequestId, strtolower((string) $dataId), MP_WEBHOOK_SECRET)) {
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

    if (($pagamento['status'] ?? null) === 'approved') {
        $externalRef = $pagamento['external_reference'] ?? '';
        if (str_starts_with($externalRef, 'venda_')) {
            $id_venda = (int) substr($externalRef, strlen('venda_'));
            $stmt = $pdo->prepare('SELECT valor_total, status FROM vendas WHERE id_venda = :id');
            $stmt->execute([':id' => $id_venda]);
            $venda = $stmt->fetch();
            if ($venda && $venda['status'] === 'Reservado') {
                finalizarVenda($pdo, $id_venda, [['forma' => 'Pix', 'valor' => (float) $venda['valor_total']]], (string) $dataId);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Webhook MP erro: ' . $e->getMessage());
}

echo json_encode(['received' => true]);
