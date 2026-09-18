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
$valor = converterMoedaBrParaFloat($_POST['valor'] ?? '0');

$stmtCliente = $pdo->prepare('SELECT saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$saldoDevedor = (float) $stmtCliente->fetchColumn();

// Pagamentos já gerados e ainda não confirmados contam contra o saldo: sem isso, duas abas
// (ou um back-button) geram duas preferências pra mesma dívida e o segundo pagamento
// desapareceria no piso do GREATEST(0, ...) na confirmação.
$stmtPendente = $pdo->prepare(
    "SELECT COALESCE(SUM(valor), 0) FROM movimentos_credito
     WHERE id_cliente = :id AND tipo = 'pagamento' AND status = 'Pendente'"
);
$stmtPendente->execute([':id' => $id_cliente]);
$totalPendente = (float) $stmtPendente->fetchColumn();

$disponivelParaPagar = $saldoDevedor - $totalPendente;

if ($valor <= 0 || $valor > $disponivelParaPagar + 0.01) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Valor inválido, ou você já tem um pagamento pendente de confirmação que cobre parte dessa dívida.'));
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

$application_fee = ceil($valor * (mpTaxaMarketplace($pdo) / 100) * 100) / 100;

$preference = [
    'items' => [[
        'title' => 'Pagamento de dívida - Brechó da Veve',
        'quantity' => 1,
        'currency_id' => 'BRL',
        'unit_price' => $valor,
    ]],
    'external_reference' => 'divida_' . $id_movimento,
    'notification_url' => urlBaseAtual() . '/integracoes/mercado_pago/webhook.php',
    'back_urls' => [
        'success' => urlBaseAtual() . '/loja/minha_divida.php',
        'pending' => urlBaseAtual() . '/loja/minha_divida.php',
        'failure' => urlBaseAtual() . '/loja/minha_divida.php',
    ],
    'auto_return' => 'approved',
    // /v1/payments provou (em produção) que o Mercado Pago valida o nome dos
    // parâmetros e rejeita o que não reconhece -- não dá pra supor que
    // /checkout/preferences seja mais tolerante. marketplace_fee é o único
    // campo documentado como correto aqui.
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
