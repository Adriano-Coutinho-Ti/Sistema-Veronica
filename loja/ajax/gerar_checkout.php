<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirClienteLogado();

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

$itens = $pdo->prepare('SELECT nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

if (empty($listaItens)) {
    header('Location: /loja/carrinho.php');
    exit;
}

$subtotalItens = array_sum(array_column($listaItens, 'subtotal'));
$valorTotalComEntrega = $subtotalItens + (float) $entrega['custo'];

$pdo->prepare('UPDATE vendas SET id_entrega = :ie, valor_total = :total WHERE id_venda = :iv')
    ->execute([':ie' => $id_entrega, ':total' => $valorTotalComEntrega, ':iv' => $id_venda]);

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    header('Location: /loja/checkout.php?erro=' . urlencode('Loja temporariamente indisponível para pagamento. Tente novamente mais tarde.'));
    exit;
}

$itensMp = [];
foreach ($listaItens as $item) {
    $itensMp[] = [
        'title' => $item['nome_produto'] . ($item['descricao_combinacao'] ? ' (' . $item['descricao_combinacao'] . ')' : ''),
        'quantity' => (int) $item['quantidade'],
        'currency_id' => 'BRL',
        'unit_price' => (float) $item['preco_unit'],
    ];
}
if ((float) $entrega['custo'] > 0) {
    $itensMp[] = ['title' => 'Entrega: ' . $entrega['nome'], 'quantity' => 1, 'currency_id' => 'BRL', 'unit_price' => (float) $entrega['custo']];
}

$application_fee = ceil($valorTotalComEntrega * 0.01 * 100) / 100;

$preference = [
    'items' => $itensMp,
    'external_reference' => 'loja_' . $id_venda,
    'notification_url' => 'https://brechodaveve.codernex.com.br/loja/api/notificacao_mp.php',
    'back_urls' => [
        'success' => 'https://brechodaveve.codernex.com.br/loja/pedido_status.php?id_venda=' . $id_venda,
        'pending' => 'https://brechodaveve.codernex.com.br/loja/pedido_status.php?id_venda=' . $id_venda,
        'failure' => 'https://brechodaveve.codernex.com.br/loja/pedido_status.php?id_venda=' . $id_venda,
    ],
    'auto_return' => 'approved',
    'marketplace_fee' => $application_fee,
    'sponsor_id' => 194420711,
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/checkout/preferences', $preference, $config['mp_access_token']);

    if ($resposta['http_code'] !== 201 || !isset($resposta['dados']['init_point'])) {
        header('Location: /loja/checkout.php?erro=' . urlencode('Erro ao gerar pagamento: ' . ($resposta['dados']['message'] ?? 'resposta inesperada do Mercado Pago')));
        exit;
    }

    $pdo->prepare('UPDATE vendas SET id_pagamento_mp = :id WHERE id_venda = :iv')
        ->execute([':id' => $resposta['dados']['id'], ':iv' => $id_venda]);

    header('Location: ' . $resposta['dados']['init_point']);
    exit;
} catch (Throwable $e) {
    header('Location: /loja/checkout.php?erro=' . urlencode('Erro ao conectar com o Mercado Pago.'));
    exit;
}
