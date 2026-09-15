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

$webhookSecret = mpAppCredenciais($pdo)['webhook_secret'];
if (!$dataId || !$xSignature || !$webhookSecret) {
    echo json_encode(['received' => true]);
    exit;
}

if (!mpValidarAssinaturaWebhook($xSignature, $xRequestId, strtolower((string) $dataId), $webhookSecret)) {
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
                // Observabilidade: o webhook irmão (produtos) compara o valor reportado pelo MP
                // com o esperado. Aqui só registramos a divergência (não bloqueia a confirmação).
                $valorReportadoMp = (float) ($pagamento['transaction_amount'] ?? 0);
                if (abs($valorReportadoMp - (float) $movimento['valor']) > 0.01) {
                    error_log('Webhook divida MP: valor reportado pelo MP (' . $valorReportadoMp . ') diverge do valor registrado (' . $movimento['valor'] . ') para id_movimento=' . $id_movimento);
                }

                // Confirmar o movimento e abater o saldo têm que acontecer juntos: se o segundo
                // UPDATE falhasse, o movimento ficaria 'Confirmado' sem nunca abater a dívida —
                // e o catch externo responde 200, então nada re-tentaria.
                $pdo->beginTransaction();
                try {
                    $confirmou = $pdo->prepare(
                        "UPDATE movimentos_credito SET status = 'Confirmado', id_pagamento_mp = :idmp
                         WHERE id_movimento = :id AND status = 'Pendente'"
                    );
                    $confirmou->execute([':idmp' => $dataId, ':id' => $id_movimento]);

                    if ($confirmou->rowCount() > 0) {
                        $abateu = $pdo->prepare(
                            'UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor)
                             WHERE id_cliente = :id AND saldo_devedor >= :valor2'
                        );
                        $abateu->execute([':valor' => $movimento['valor'], ':valor2' => $movimento['valor'], ':id' => $movimento['id_cliente']]);

                        if ($abateu->rowCount() === 0) {
                            error_log('Webhook divida MP: saldo_devedor já estava abaixo do valor confirmado (id_movimento=' . $id_movimento . ', valor=' . $movimento['valor'] . ')');
                        }
                    }

                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
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
