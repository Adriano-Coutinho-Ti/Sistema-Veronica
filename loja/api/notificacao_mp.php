<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/loja.php';
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
    error_log('Webhook loja MP: assinatura inválida para data.id=' . $dataId);
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
        if (str_starts_with($externalRef, 'loja_')) {
            $id_venda = (int) substr($externalRef, strlen('loja_'));
            $stmt = $pdo->prepare("SELECT status FROM vendas WHERE id_venda = :id AND origem = 'loja'");
            $stmt->execute([':id' => $id_venda]);
            $venda = $stmt->fetch();

            if ($venda && $venda['status'] === 'Reservado') {
                $valorPago = (float) ($pagamento['transaction_amount'] ?? 0);
                $resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Mercado Pago', 'valor' => $valorPago]], (string) $dataId);

                if (!$resultado['success']) {
                    error_log('Webhook loja MP: falha ao finalizar venda ' . $id_venda . ': ' . $resultado['message']);
                    // A reserva ainda está segurando o estoque (finalizarVenda() não
                    // debita nada quando rejeita) — precisa devolver antes de cancelar,
                    // senão o item fica preso, reservado pra sempre, sem ninguém poder
                    // comprá-lo de novo.
                    devolverReservaDaVenda($pdo, $id_venda);
                    $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id AND status = 'Reservado'")
                        ->execute([':id' => $id_venda]);
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Webhook loja MP erro: ' . $e->getMessage());
}

echo json_encode(['received' => true]);
