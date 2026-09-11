<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pedidos.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$status_entrega = $_POST['status_entrega'] ?? '';

if (!in_array($status_entrega, STATUS_ENTREGA_VALIDOS, true)) {
    echo json_encode(['success' => false, 'message' => 'Etapa inválida.']);
    exit;
}

$stmt = $pdo->prepare("UPDATE vendas SET status_entrega = :se WHERE id_venda = :id AND origem = 'loja' AND status = 'Pago'");
$stmt->execute([':se' => $status_entrega, ':id' => $id_venda]);

if ($stmt->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'Pedido não encontrado ou não está pago.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Status atualizado.']);
