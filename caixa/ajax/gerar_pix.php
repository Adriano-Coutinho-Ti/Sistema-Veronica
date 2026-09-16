<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$stmt = $pdo->prepare("SELECT valor_total FROM vendas WHERE id_venda = :id AND id_usuario = :iu AND status = 'Reservado'");
$stmt->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
$venda = $stmt->fetch();

if (!$venda) {
    echo json_encode(['success' => false, 'message' => 'Venda não encontrada ou já finalizada.']);
    exit;
}

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Loja não conectada ao Mercado Pago. Conecte em Configurações.']);
    exit;
}

$notification_url = urlBaseAtual() . '/integracoes/mercado_pago/webhook.php';

$valor_total = (float) $venda['valor_total'];
$application_fee = ceil($valor_total * 0.01 * 100) / 100;
$external_reference = 'venda_' . $id_venda;

$payload = [
    'transaction_amount' => round($valor_total, 2),
    'description' => 'Venda #' . $id_venda,
    'payment_method_id' => 'pix',
    'payer' => ['email' => 'pagamentos@brechodaveve.com.br'],
    'external_reference' => $external_reference,
    'notification_url' => $notification_url,
    // /v1/payments valida o nome dos parâmetros e rejeita marketplace_fee
    // (erro real confirmado em produção) -- application_fee é o único campo
    // correto aqui.
    'application_fee' => $application_fee,
    'sponsor_id' => 194420711,
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/v1/payments', $payload, $config['mp_access_token'], [
        'X-Idempotency-Key: ' . $external_reference . '-' . bin2hex(random_bytes(6)),
    ]);

    if ($resposta['http_code'] !== 201 || !isset($resposta['dados']['id'])) {
        echo json_encode(['success' => false, 'message' => 'Erro ao gerar Pix: ' . ($resposta['dados']['message'] ?? 'resposta inesperada do Mercado Pago')]);
        exit;
    }

    $qr_base64 = $resposta['dados']['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null;
    $qr_copia_cola = $resposta['dados']['point_of_interaction']['transaction_data']['qr_code'] ?? null;

    if (!$qr_base64 || !$qr_copia_cola) {
        echo json_encode(['success' => false, 'message' => 'Mercado Pago não devolveu o QR Code do Pix.']);
        exit;
    }

    $pdo->prepare('UPDATE vendas SET id_pagamento_mp = :id WHERE id_venda = :iv')
        ->execute([':id' => $resposta['dados']['id'], ':iv' => $id_venda]);

    echo json_encode(['success' => true, 'id_pagamento_mp' => $resposta['dados']['id'], 'qr_code_base64' => $qr_base64, 'qr_code' => $qr_copia_cola]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
