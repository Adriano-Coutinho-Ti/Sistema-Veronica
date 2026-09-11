<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirClienteLogado();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /loja/minha_divida.php');
    exit;
}

$id_cliente = (int) $_SESSION['id_cliente'];
$valor = (float) str_replace(',', '.', $_POST['valor'] ?? '0');

$stmtCliente = $pdo->prepare('SELECT saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$saldoDevedor = (float) $stmtCliente->fetchColumn();

if ($valor <= 0 || $valor > $saldoDevedor + 0.01) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Valor inválido.'));
    exit;
}

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Loja temporariamente indisponível para pagamento. Tente novamente mais tarde.'));
    exit;
}

$pdo->prepare(
    "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, forma_pagamento)
     VALUES (:ic, 'pagamento', 'Pendente', :valor, 'Mercado Pago')"
)->execute([':ic' => $id_cliente, ':valor' => $valor]);
$id_movimento = (int) $pdo->lastInsertId();

$application_fee = ceil($valor * 0.01 * 100) / 100;

$preference = [
    'items' => [[
        'title' => 'Pagamento de dívida - Brechó da Veve',
        'quantity' => 1,
        'currency_id' => 'BRL',
        'unit_price' => $valor,
    ]],
    'external_reference' => 'divida_' . $id_movimento,
    'notification_url' => 'https://brechodaveve.codernex.com.br/loja/api/notificacao_divida_mp.php',
    'back_urls' => [
        'success' => 'https://brechodaveve.codernex.com.br/loja/minha_divida.php',
        'pending' => 'https://brechodaveve.codernex.com.br/loja/minha_divida.php',
        'failure' => 'https://brechodaveve.codernex.com.br/loja/minha_divida.php',
    ],
    'auto_return' => 'approved',
    'marketplace_fee' => $application_fee,
    'sponsor_id' => 194420711,
    'excluded_payment_types' => [
        ['id' => 'ticket'],
    ],
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/checkout/preferences', $preference, $config['mp_access_token']);

    if ($resposta['http_code'] !== 201 || !isset($resposta['dados']['init_point'])) {
        header('Location: /loja/minha_divida.php?erro=' . urlencode('Erro ao gerar pagamento: ' . ($resposta['dados']['message'] ?? 'resposta inesperada do Mercado Pago')));
        exit;
    }

    header('Location: ' . $resposta['dados']['init_point']);
    exit;
} catch (Throwable $e) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Erro ao conectar com o Mercado Pago.'));
    exit;
}
