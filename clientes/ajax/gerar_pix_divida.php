<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirLogin();
header('Content-Type: application/json');

$id_cliente = (int) ($_POST['id_cliente'] ?? 0);
$valor = converterMoedaBrParaFloat($_POST['valor'] ?? '0');

$stmt = $pdo->prepare('SELECT nome, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();

if (!$cliente) {
    echo json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
    exit;
}

if ($valor <= 0 || $valor > (float) $cliente['saldo_devedor'] + 0.01) {
    echo json_encode(['success' => false, 'message' => 'Valor inválido — não pode passar da dívida atual.']);
    exit;
}

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Loja não conectada ao Mercado Pago. Conecte em Configurações.']);
    exit;
}

// Fica 'Pendente' até o webhook confirmar (processarWebhookDivida(), o mesmo
// já usado pelo pagamento de dívida que o próprio cliente gera pela loja
// online em loja/ajax/gerar_checkout_divida.php) -- nunca abate o saldo
// devedor antes da confirmação de verdade.
$pdo->prepare("INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, criado_por) VALUES (:ic, 'pagamento', 'Pendente', :valor, :criado_por)")
    ->execute([':ic' => $id_cliente, ':valor' => $valor, ':criado_por' => (int) $_SESSION['id_usuario']]);
$id_movimento = (int) $pdo->lastInsertId();

$application_fee = ceil($valor * (mpTaxaMarketplace($pdo) / 100) * 100) / 100;

$payload = [
    'transaction_amount' => round($valor, 2),
    'description' => 'Pagamento de dívida - ' . $cliente['nome'],
    'payment_method_id' => 'pix',
    'payer' => ['email' => emailPagadorPadrao()],
    'external_reference' => 'divida_' . $id_movimento,
    'notification_url' => urlBaseAtual() . '/integracoes/mercado_pago/webhook.php',
    // /v1/payments valida o nome dos parâmetros e rejeita marketplace_fee
    // (erro real confirmado em produção) -- application_fee é o único campo
    // correto aqui.
    'application_fee' => $application_fee,
    'sponsor_id' => 194420711,
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/v1/payments', $payload, $config['mp_access_token'], [
        'X-Idempotency-Key: divida_' . $id_movimento . '-' . bin2hex(random_bytes(6)),
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

    $pdo->prepare('UPDATE movimentos_credito SET id_pagamento_mp = :id WHERE id_movimento = :im')
        ->execute([':id' => $resposta['dados']['id'], ':im' => $id_movimento]);

    echo json_encode(['success' => true, 'id_movimento' => $id_movimento, 'qr_code_base64' => $qr_base64, 'qr_code' => $qr_copia_cola]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
