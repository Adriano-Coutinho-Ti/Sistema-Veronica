<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_GET['id_venda'] ?? 0);

// Check ownership: venda must belong to the logged-in operator
$stmtV = $pdo->prepare('SELECT valor_total FROM vendas WHERE id_venda = :id AND id_usuario = :iu');
$stmtV->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
$total = (float) $stmtV->fetchColumn();

// If venda doesn't exist or doesn't belong to this operator, return empty cart
if ($total === 0.0 && $stmtV->rowCount() === 0) {
    echo json_encode(['itens' => [], 'total' => 0]);
    exit;
}

$stmt = $pdo->prepare('SELECT id_item, nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
$stmt->execute([':id' => $id_venda]);
$itens = $stmt->fetchAll();
foreach ($itens as &$item) {
    $item['subtotal'] = (float) $item['subtotal'];
    $item['preco_unit'] = (float) $item['preco_unit'];
}
unset($item);

echo json_encode(['itens' => $itens, 'total' => $total]);
