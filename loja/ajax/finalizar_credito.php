<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirClienteLogado();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /loja/checkout.php');
    exit;
}

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

if (!$id_venda) {
    header('Location: /loja/carrinho.php');
    exit;
}

$id_entrega = (int) ($_POST['id_entrega'] ?? 0);
$endereco = trim($_POST['endereco'] ?? '');

$stmtEntrega = $pdo->prepare('SELECT id_entrega, nome, tipo, custo FROM formas_entrega WHERE id_entrega = :id AND ativo = 1');
$stmtEntrega->execute([':id' => $id_entrega]);
$entrega = $stmtEntrega->fetch();

if (!$entrega) {
    header('Location: /loja/checkout.php?erro=' . urlencode('Selecione uma forma de entrega válida.'));
    exit;
}

if ($entrega['tipo'] !== 'retirada' && $endereco !== '') {
    $pdo->prepare('UPDATE clientes SET endereco = :endereco WHERE id_cliente = :id')
        ->execute([':endereco' => $endereco, ':id' => $id_cliente]);
}

$itens = $pdo->prepare(
    "SELECT id_item FROM itens_venda
     WHERE id_venda = :id AND NOT (id_produto_variacao IS NULL AND nome_produto = 'Entrega')"
);
$itens->execute([':id' => $id_venda]);
if (empty($itens->fetchAll())) {
    header('Location: /loja/carrinho.php');
    exit;
}

$valorTotalComEntrega = definirEntregaDaVenda($pdo, $id_venda, $entrega);

$resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Linha de Crédito', 'valor' => $valorTotalComEntrega]]);

if (!$resultado['success']) {
    header('Location: /loja/checkout.php?erro=' . urlencode($resultado['message']));
    exit;
}

header('Location: /loja/pedido_status.php?id_venda=' . $id_venda);
exit;
