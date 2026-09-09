<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$stmt = $pdo->prepare('SELECT status, id_pagamento_mp, valor_total FROM vendas WHERE id_venda = :id AND id_usuario = :iu');
$stmt->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
$venda = $stmt->fetch();

if (!$venda) {
    echo json_encode(['success' => false, 'aprovado' => false, 'message' => 'Venda não encontrada.']);
    exit;
}

if ($venda['status'] === 'Pago') {
    echo json_encode(['success' => true, 'aprovado' => true, 'redirect' => '/caixa/comprovante.php?id_venda=' . $id_venda]);
    exit;
}

if (empty($venda['id_pagamento_mp'])) {
    echo json_encode(['success' => true, 'aprovado' => false]);
    exit;
}

try {
    $config = mpConfig($pdo);
    $resposta = mpChamarApi('GET', 'https://api.mercadopago.com/v1/payments/' . $venda['id_pagamento_mp'], null, $config['mp_access_token']);

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
        // Usa o valor que o Mercado Pago confirma ter recebido, não uma releitura do
        // total atual da venda — se o carrinho mudou depois do QR gerado, o cliente só
        // pagou o valor antigo, e finalizarVenda() precisa comparar contra o valor
        // pago de verdade (o check de suficiência dela usa o total atual da venda).
        $valorPago = (float) ($resposta['dados']['transaction_amount'] ?? 0);
        $resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Pix', 'valor' => $valorPago]], (string) $venda['id_pagamento_mp']);
        echo json_encode(['success' => true, 'aprovado' => $resultado['success'], 'redirect' => $resultado['redirect'], 'message' => $resultado['message']]);
    } else {
        echo json_encode(['success' => true, 'aprovado' => false]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'aprovado' => false, 'message' => $e->getMessage()]);
}
