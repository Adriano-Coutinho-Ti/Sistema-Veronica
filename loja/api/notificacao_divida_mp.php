<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/mp_client.php';

// Sempre responde 200 pro Mercado Pago não ficar re-tentando indefinidamente —
// erros são só registrados em log, nunca retornados como HTTP de erro aqui.
http_response_code(200);
header('Content-Type: application/json');

$dataId = $_GET['data_id'] ?? ($_GET['id'] ?? null);
$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';

if (!$dataId || !$xSignature || !defined('MP_WEBHOOK_SECRET')) {
    echo json_encode(['received' => true]);
    exit;
}

if (!mpValidarAssinaturaWebhook($xSignature, $xRequestId, strtolower((string) $dataId), MP_WEBHOOK_SECRET)) {
    error_log('Webhook divida MP: assinatura inválida para data.id=' . $dataId);
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
    $statusPagamento = $pagamento['status'] ?? null;

    $externalRef = $pagamento['external_reference'] ?? '';
    if (str_starts_with($externalRef, 'divida_')) {
        $id_movimento = (int) substr($externalRef, strlen('divida_'));

        $stmt = $pdo->prepare("SELECT id_cliente, valor, status FROM movimentos_credito WHERE id_movimento = :id AND tipo = 'pagamento'");
        $stmt->execute([':id' => $id_movimento]);
        $movimento = $stmt->fetch();

        if ($movimento && $movimento['status'] === 'Pendente') {
            if ($statusPagamento === 'approved') {
                $confirmou = $pdo->prepare(
                    "UPDATE movimentos_credito SET status = 'Confirmado', id_pagamento_mp = :idmp
                     WHERE id_movimento = :id AND status = 'Pendente'"
                );
                $confirmou->execute([':idmp' => $dataId, ':id' => $id_movimento]);

                if ($confirmou->rowCount() > 0) {
                    $pdo->prepare('UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor) WHERE id_cliente = :id')
                        ->execute([':valor' => $movimento['valor'], ':id' => $movimento['id_cliente']]);
                }
            } elseif (in_array($statusPagamento, ['rejected', 'cancelled'], true)) {
                $pdo->prepare(
                    "UPDATE movimentos_credito SET status = 'Cancelado', id_pagamento_mp = :idmp
                     WHERE id_movimento = :id AND status = 'Pendente'"
                )->execute([':idmp' => $dataId, ':id' => $id_movimento]);
            }
        }
    }
} catch (Throwable $e) {
    error_log('Webhook divida MP erro: ' . $e->getMessage());
}

echo json_encode(['received' => true]);
