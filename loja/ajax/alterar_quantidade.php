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
$delta = (int) ($_POST['delta'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

if (!in_array($delta, [1, -1], true)) {
    echo json_encode(['success' => false, 'message' => 'Operação inválida.']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "SELECT iv.id_item, iv.id_venda, iv.id_produto_variacao, iv.quantidade, iv.preco_unit
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

    if ($item['id_produto_variacao'] === null) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Esse item não pode ter a quantidade alterada.']);
        exit;
    }

    if ($delta === 1) {
        $reservou = $pdo->prepare(
            'UPDATE produto_variacoes SET estoque_reservado = estoque_reservado + 1
             WHERE id_produto_variacao = :id AND (estoque - estoque_reservado) >= 1'
        );
        $reservou->execute([':id' => $item['id_produto_variacao']]);

        if ($reservou->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Não há mais estoque disponível dessa opção.']);
            exit;
        }

        $novaQuantidade = (int) $item['quantidade'] + 1;
        $novoSubtotal = (float) $item['preco_unit'] * $novaQuantidade;
        $pdo->prepare('UPDATE itens_venda SET quantidade = :q, subtotal = :s WHERE id_item = :id')
            ->execute([':q' => $novaQuantidade, ':s' => $novoSubtotal, ':id' => $item['id_item']]);

        $novoTotal = recalcularTotalVenda($pdo, (int) $item['id_venda']);
        $pdo->commit();
        echo json_encode(['success' => true, 'removido' => false, 'quantidade' => $novaQuantidade, 'subtotal' => $novoSubtotal, 'total' => $novoTotal]);
        exit;
    }

    // delta === -1
    if ((int) $item['quantidade'] <= 1) {
        $pdo->prepare('DELETE FROM itens_venda WHERE id_item = :id')->execute([':id' => $item['id_item']]);
        $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - :qtd), liberado_em = NOW() WHERE id_produto_variacao = :id')
            ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);

        $novoTotal = recalcularTotalVenda($pdo, (int) $item['id_venda']);
        $pdo->commit();
        echo json_encode(['success' => true, 'removido' => true, 'total' => $novoTotal]);
        exit;
    }

    $novaQuantidade = (int) $item['quantidade'] - 1;
    $novoSubtotal = (float) $item['preco_unit'] * $novaQuantidade;
    $pdo->prepare('UPDATE itens_venda SET quantidade = :q, subtotal = :s WHERE id_item = :id')
        ->execute([':q' => $novaQuantidade, ':s' => $novoSubtotal, ':id' => $item['id_item']]);
    $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - 1), liberado_em = NOW() WHERE id_produto_variacao = :id')
        ->execute([':id' => $item['id_produto_variacao']]);

    $novoTotal = recalcularTotalVenda($pdo, (int) $item['id_venda']);
    $pdo->commit();
    echo json_encode(['success' => true, 'removido' => false, 'quantidade' => $novaQuantidade, 'subtotal' => $novoSubtotal, 'total' => $novoTotal]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro ao alterar quantidade.']);
}
