<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirAdmin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);

$stmt = $pdo->prepare("SELECT id_pagamento_mp, status FROM vendas WHERE id_venda = :id AND origem = 'loja'");
$stmt->execute([':id' => $id_venda]);
$venda = $stmt->fetch();

if (!$venda || $venda['status'] !== 'Reservado' || empty($venda['id_pagamento_mp'])) {
    echo json_encode(['success' => false, 'message' => 'Pedido não encontrado ou não tem pagamento pendente.']);
    exit;
}

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Loja não conectada ao Mercado Pago.']);
    exit;
}

try {
    // Confere o status de verdade antes de mexer em qualquer coisa — o webhook
    // pode ter confirmado o pagamento bem na hora que o lojista clicou em
    // cancelar; nesse caso não dá (nem deve) pra cancelar mais.
    $consulta = mpChamarApi('GET', 'https://api.mercadopago.com/v1/payments/' . $venda['id_pagamento_mp'], null, $config['mp_access_token']);
    $statusAtual = $consulta['dados']['status'] ?? null;

    if ($statusAtual === 'approved') {
        echo json_encode(['success' => false, 'message' => 'Esse pagamento já foi aprovado — não dá pra cancelar. Atualize a página.']);
        exit;
    }

    if (in_array($statusAtual, ['pending', 'in_process'], true)) {
        $resposta = mpChamarApi('PUT', 'https://api.mercadopago.com/v1/payments/' . $venda['id_pagamento_mp'], ['status' => 'cancelled'], $config['mp_access_token']);
        if ($resposta['http_code'] >= 400) {
            echo json_encode(['success' => false, 'message' => 'Erro ao cancelar no Mercado Pago: ' . ($resposta['dados']['message'] ?? 'tente novamente.')]);
            exit;
        }
    }
    // Se já estava rejected/cancelled do lado do Mercado Pago, não precisa
    // chamar o cancelamento de novo — só segue pra liberar o pedido aqui.
} catch (Throwable $e) {
    error_log('cancelar_pagamento_mp: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao conectar com o Mercado Pago.']);
    exit;
}

cancelarVendaReservadaLoja($pdo, $id_venda);
echo json_encode(['success' => true, 'message' => 'Pagamento cancelado. Estoque liberado.']);
