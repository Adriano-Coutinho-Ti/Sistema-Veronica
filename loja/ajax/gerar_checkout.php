<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
require_once __DIR__ . '/../../includes/mp_client.php';
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

// A linha de entrega é gravada como um item da venda (ver abaixo), então ela precisa ficar
// fora desta lista: aqui só entram os produtos de verdade, que alimentam as linhas da
// preferência do Mercado Pago e a checagem de carrinho vazio.
$itens = $pdo->prepare(
    "SELECT nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal
     FROM itens_venda
     WHERE id_venda = :id AND NOT (id_produto_variacao IS NULL AND nome_produto = 'Entrega')"
);
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

if (empty($listaItens)) {
    header('Location: /loja/carrinho.php');
    exit;
}

// Remove qualquer linha de entrega anterior (cliente pode ter voltado e trocado a forma de
// entrega antes de tentar pagar de novo) e insere a atual como um item sem produto vinculado
// — id_produto_variacao NULL, que finalizarVenda()/devolverReservaDaVenda() já ignoram.
$pdo->prepare("DELETE FROM itens_venda WHERE id_venda = :iv AND id_produto_variacao IS NULL AND nome_produto = 'Entrega'")
    ->execute([':iv' => $id_venda]);

if ((float) $entrega['custo'] > 0) {
    $pdo->prepare(
        'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
         VALUES (:iv, :nome, :desc, NULL, 1, :preco, :subtotal)'
    )->execute([
        ':iv' => $id_venda,
        ':nome' => 'Entrega',
        ':desc' => $entrega['nome'],
        ':preco' => (float) $entrega['custo'],
        ':subtotal' => (float) $entrega['custo'],
    ]);
}

$pdo->prepare('UPDATE vendas SET id_entrega = :ie WHERE id_venda = :iv')
    ->execute([':ie' => $id_entrega, ':iv' => $id_venda]);

$valorTotalComEntrega = recalcularTotalVenda($pdo, $id_venda);

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
    // A loja online aceita só Pix e cartão. Boleto ('ticket') fica de fora porque leva de 1 a 3
    // dias pra compensar, incompatível com o prazo de reserva do carrinho (15 minutos).
    'excluded_payment_types' => [
        ['id' => 'ticket'],
    ],
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
