<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/credito.php';
exigirLogin();
header('Content-Type: application/json');

$id_cliente = (int) ($_GET['id_cliente'] ?? 0);

$stmtCliente = $pdo->prepare('SELECT nome, prazo_dias_credito FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

if (!$cliente) {
    echo json_encode(['success' => false, 'message' => 'Cliente não encontrado.']);
    exit;
}

$situacao = calcularSituacaoCreditoCliente($pdo, $id_cliente, (int) $cliente['prazo_dias_credito']);

$stmtExtrato = $pdo->prepare(
    'SELECT id_movimento, tipo, status, valor, forma_pagamento, data_movimento, id_venda, observacao
     FROM movimentos_credito
     WHERE id_cliente = :id
     ORDER BY data_movimento DESC'
);
$stmtExtrato->execute([':id' => $id_cliente]);

$movimentos = [];
foreach ($stmtExtrato->fetchAll() as $mov) {
    $sit = $mov['tipo'] === 'compra' ? ($situacao['compras'][(int) $mov['id_movimento']] ?? null) : null;
    $movimentos[] = [
        'tipo' => $mov['tipo'],
        'status' => $mov['status'],
        'valor' => (float) $mov['valor'],
        'forma_pagamento' => $mov['forma_pagamento'],
        'manual' => $mov['tipo'] === 'compra' && $mov['id_venda'] === null,
        'observacao' => $mov['observacao'],
        'data_movimento' => date('d/m/Y H:i', strtotime($mov['data_movimento'])),
        'em_aberto' => $sit['em_aberto'] ?? null,
        'vencido' => $sit['vencido'] ?? null,
        'dias_atraso' => $sit['dias_atraso'] ?? null,
    ];
}

echo json_encode([
    'success' => true,
    'nome' => $cliente['nome'],
    'total_aberto' => $situacao['total_aberto'],
    'total_vencido' => $situacao['total_vencido'],
    'movimentos' => $movimentos,
]);
