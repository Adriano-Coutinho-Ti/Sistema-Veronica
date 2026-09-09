<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_GET['id_venda'] ?? 0);

$stmt = $pdo->prepare('SELECT id_item, nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
$stmt->execute([':id' => $id_venda]);
$itens = $stmt->fetchAll();
foreach ($itens as &$item) {
    $item['subtotal'] = (float) $item['subtotal'];
    $item['preco_unit'] = (float) $item['preco_unit'];
}
unset($item);

$stmtV = $pdo->prepare('SELECT valor_total FROM vendas WHERE id_venda = :id');
$stmtV->execute([':id' => $id_venda]);
$total = (float) $stmtV->fetchColumn();

echo json_encode(['itens' => $itens, 'total' => $total]);
