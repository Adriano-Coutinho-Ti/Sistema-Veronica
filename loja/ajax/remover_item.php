<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$id_item = (int) ($_POST['id_item'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT iv.id_item, iv.id_venda, iv.id_produto_variacao, iv.quantidade
         FROM itens_venda iv
         JOIN vendas v ON v.id_venda = iv.id_venda
         WHERE iv.id_item = :id AND v.id_cliente = :ic AND v.origem = 'loja' AND v.status = 'Reservado'
         FOR UPDATE"
    );
    $stmt->execute([':id' => $id_item, ':ic' => $id_cliente]);
    $item = $stmt->fetch();

    if (!$item) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Item não encontrado.']);
        exit;
    }

    $del = $pdo->prepare('DELETE FROM itens_venda WHERE id_item = :id');
    $del->execute([':id' => $item['id_item']]);

    if ($del->rowCount() === 1 && $item['id_produto_variacao'] !== null) {
        $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - :qtd), liberado_em = NOW() WHERE id_produto_variacao = :id')
            ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);
    }

    recalcularTotalVenda($pdo, (int) $item['id_venda']);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro ao remover item.']);
}
