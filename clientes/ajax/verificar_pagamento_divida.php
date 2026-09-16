<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/credito.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirLogin();
header('Content-Type: application/json');

$id_movimento = (int) ($_POST['id_movimento'] ?? 0);
$stmt = $pdo->prepare("SELECT status, id_pagamento_mp FROM movimentos_credito WHERE id_movimento = :id AND tipo = 'pagamento'");
$stmt->execute([':id' => $id_movimento]);
$movimento = $stmt->fetch();

if (!$movimento) {
    echo json_encode(['success' => false, 'aprovado' => false, 'message' => 'Pagamento não encontrado.']);
    exit;
}

if ($movimento['status'] === 'Confirmado') {
    echo json_encode(['success' => true, 'aprovado' => true]);
    exit;
}

if (empty($movimento['id_pagamento_mp'])) {
    echo json_encode(['success' => true, 'aprovado' => false]);
    exit;
}

try {
    $config = mpConfig($pdo);
    $resposta = mpChamarApi('GET', 'https://api.mercadopago.com/v1/payments/' . $movimento['id_pagamento_mp'], null, $config['mp_access_token']);

    if ($resposta['http_code'] >= 400) {
        echo json_encode([
            'success' => false,
            'aprovado' => false,
            'message' => 'Erro ao consultar o Mercado Pago. Se o problema persistir, reconecte a loja em /integracoes/mercado_pago/conectar.php',
        ]);
        exit;
    }

    $statusMp = $resposta['dados']['status'] ?? null;

    if ($statusMp === 'approved') {
        processarWebhookDivida($pdo, $id_movimento, $resposta['dados'], (string) $movimento['id_pagamento_mp']);
        echo json_encode(['success' => true, 'aprovado' => true]);
    } else {
        echo json_encode(['success' => true, 'aprovado' => false]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'aprovado' => false, 'message' => $e->getMessage()]);
}
