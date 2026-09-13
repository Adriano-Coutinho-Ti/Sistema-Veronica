<?php
/**
 * Venda avulsa: o operador vende algo que não está cadastrado no sistema
 * (uma peça que não foi fotografada/cadastrada ainda, por exemplo) — item
 * sem id_produto_variacao (a coluna é NULL-tolerante de propósito, mesmo
 * esquema usado quando um produto é apagado depois de vendido).
 */
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$valor = (float) str_replace(',', '.', $_POST['valor'] ?? '0');

$stmtV = $pdo->prepare(
    "SELECT v.id_venda FROM vendas v
     JOIN caixa_sessoes cs ON cs.id_caixa = v.id_caixa
     WHERE v.id_venda = :id AND v.id_usuario = :iu AND v.status = 'Reservado' AND cs.status = 'aberto'"
);
$stmtV->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
if (!$stmtV->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Venda não encontrada ou já finalizada.']);
    exit;
}

if ($valor <= 0) {
    echo json_encode(['success' => false, 'message' => 'Informe um valor válido.']);
    exit;
}

$pdo->prepare(
    'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
     VALUES (:iv, :nome, NULL, NULL, 1, :preco, :preco)'
)->execute([
    ':iv' => $id_venda,
    ':nome' => 'Venda avulsa',
    ':preco' => $valor,
]);

recalcularTotalVenda($pdo, $id_venda);

echo json_encode(['success' => true]);
