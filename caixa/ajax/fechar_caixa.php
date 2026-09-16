<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$caixa = exigirCaixaAberto($pdo);

$valor_final = (float) str_replace(',', '.', $_POST['valor_final_informado'] ?? '0');
$observacao = trim($_POST['observacao_fechamento'] ?? '') ?: null;

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(vp.valor), 0) AS total
     FROM venda_pagamentos vp
     JOIN vendas v ON v.id_venda = vp.id_venda
     WHERE v.id_caixa = :ic AND vp.forma_pagamento = 'Dinheiro'"
);
$stmt->execute([':ic' => $caixa['id_caixa']]);
$total_dinheiro_vendas = (float) $stmt->fetchColumn();

// Pagamentos de dívida recebidos em dinheiro nesta sessão também estão na gaveta.
$stmtDivida = $pdo->prepare(
    "SELECT COALESCE(SUM(valor), 0) AS total
     FROM movimentos_credito
     WHERE id_caixa = :ic AND tipo = 'pagamento' AND forma_pagamento = 'Dinheiro'"
);
$stmtDivida->execute([':ic' => $caixa['id_caixa']]);
$total_dinheiro_divida = (float) $stmtDivida->fetchColumn();

$total_dinheiro = $total_dinheiro_vendas + $total_dinheiro_divida;
$valor_esperado = (float) $caixa['valor_inicial'] + $total_dinheiro;
$diferenca = $valor_final - $valor_esperado;

$pdo->prepare(
    "UPDATE caixa_sessoes SET status = 'fechado', fechado_por = :fp, data_fechamento = NOW(),
            valor_final_informado = :vf, valor_esperado = :ve, diferenca = :dif, observacao_fechamento = :obs
     WHERE id_caixa = :ic AND status = 'aberto'"
)->execute([
    ':fp' => $_SESSION['id_usuario'],
    ':vf' => $valor_final,
    ':ve' => $valor_esperado,
    ':dif' => $diferenca,
    ':obs' => $observacao,
    ':ic' => $caixa['id_caixa'],
]);

unset($_SESSION['id_caixa_selecionado']);
header('Location: ' . (quantidadeCaixas($pdo) > 1 ? '/caixa/selecionar.php?fechado=1' : '/caixa/abertura.php?fechado=1'));
exit;
