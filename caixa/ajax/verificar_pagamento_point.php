<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$stmt = $pdo->prepare('SELECT status, id_order_mp FROM vendas WHERE id_venda = :id AND id_usuario = :iu');
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

if (empty($venda['id_order_mp'])) {
    echo json_encode(['success' => true, 'aprovado' => false, 'cancelado' => true]);
    exit;
}

try {
    $config = mpConfig($pdo);
    $resposta = mpChamarApi('GET', 'https://api.mercadopago.com/v1/orders/' . $venda['id_order_mp'], null, $config['mp_access_token']);

    if ($resposta['http_code'] >= 400) {
        echo json_encode([
            'success' => false,
            'aprovado' => false,
            'message' => 'Erro ao consultar o Mercado Pago. Se o problema persistir, reconecte a loja em /integracoes/mercado_pago/conectar.php',
        ]);
        exit;
    }

    $order = $resposta['dados'];
    processarWebhookVendaCaixaPoint($pdo, $id_venda, $order, (string) $venda['id_order_mp']);

    $stmtDepois = $pdo->prepare('SELECT status, id_order_mp FROM vendas WHERE id_venda = :id');
    $stmtDepois->execute([':id' => $id_venda]);
    $vendaDepois = $stmtDepois->fetch();

    if ($vendaDepois['status'] === 'Pago') {
        echo json_encode(['success' => true, 'aprovado' => true, 'redirect' => '/caixa/comprovante.php?id_venda=' . $id_venda]);
    } elseif (empty($vendaDepois['id_order_mp'])) {
        // processarWebhookVendaCaixaPoint() liberou a maquininha (order cancelada/expirada) --
        // avisa a tela pra fechar o popup e voltar pro lançamento manual.
        echo json_encode(['success' => true, 'aprovado' => false, 'cancelado' => true]);
    } else {
        echo json_encode(['success' => true, 'aprovado' => false]);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'aprovado' => false, 'message' => $e->getMessage()]);
}
