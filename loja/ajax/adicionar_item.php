<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

// Fora do try/catch principal (que só começa depois do beginTransaction()) — sem isso uma
// exceção aqui sairia como corpo vazio e o fetch() do front-end quebraria no r.json().
try {
    liberarReservasExpiradas($pdo);
} catch (Throwable $e) {
    error_log('adicionar_item: erro ao liberar reservas expiradas: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Erro ao processar. Tente novamente.']);
    exit;
}

$id_produto_variacao = (int) ($_POST['id_produto_variacao'] ?? 0);
$quantidade = max(1, (int) ($_POST['quantidade'] ?? 1));
$id_cliente = (int) $_SESSION['id_cliente'];

if (!clienteEmailVerificado($pdo, $id_cliente)) {
    echo json_encode(['success' => false, 'message' => 'Confirme seu e-mail antes de adicionar itens ao carrinho. Vá em "Minha conta" pra reenviar o e-mail de verificação.']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmtProduto = $pdo->prepare(
        'SELECT p.nome AS nome_produto, p.estoque_gerenciado, COALESCE(pv.preco, p.preco_base) AS preco,
                GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao
         FROM produto_variacoes pv
         JOIN produtos p ON p.id_produto = pv.id_produto
         LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
         LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
         WHERE pv.id_produto_variacao = :id AND p.ativo = 1
         GROUP BY p.nome, p.estoque_gerenciado, pv.preco, p.preco_base'
    );
    $stmtProduto->execute([':id' => $id_produto_variacao]);
    $produto = $stmtProduto->fetch();

    if (!$produto) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Produto não encontrado.']);
        exit;
    }

    // Produto sem controle de estoque pode ser adicionado livremente, sem
    // reservar nada em produto_variacoes — não há quantidade pra checar.
    if ((int) $produto['estoque_gerenciado']) {
        $reservou = $pdo->prepare(
            'UPDATE produto_variacoes SET estoque_reservado = estoque_reservado + :qtd
             WHERE id_produto_variacao = :id AND (estoque - estoque_reservado) >= :qtd2'
        );
        $reservou->execute([':qtd' => $quantidade, ':id' => $id_produto_variacao, ':qtd2' => $quantidade]);

        if ($reservou->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Esse item não está mais disponível nessa quantidade.']);
            exit;
        }
    }

    $id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);
    if (!$id_venda) {
        $pdo->prepare("INSERT INTO vendas (id_cliente, origem, status) VALUES (:ic, 'loja', 'Reservado')")
            ->execute([':ic' => $id_cliente]);
        $id_venda = (int) $pdo->lastInsertId();
    }

    $preco_unit = (float) $produto['preco'];
    $subtotal = $preco_unit * $quantidade;

    $pdo->prepare(
        'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
         VALUES (:iv, :nome, :desc, :ipv, :qtd, :preco, :subtotal)'
    )->execute([
        ':iv' => $id_venda,
        ':nome' => $produto['nome_produto'],
        ':desc' => $produto['descricao_combinacao'] ?: null,
        ':ipv' => $id_produto_variacao,
        ':qtd' => $quantidade,
        ':preco' => $preco_unit,
        ':subtotal' => $subtotal,
    ]);

    recalcularTotalVenda($pdo, $id_venda);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'message' => 'Erro ao adicionar ao carrinho.']);
}
