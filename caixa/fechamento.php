<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

$caixa = exigirCaixaAberto($pdo);

$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(vp.valor), 0) AS total
     FROM venda_pagamentos vp
     JOIN vendas v ON v.id_venda = vp.id_venda
     WHERE v.id_caixa = :ic AND vp.forma_pagamento = 'Dinheiro'"
);
$stmt->execute([':ic' => $caixa['id_caixa']]);
$total_dinheiro = (float) $stmt->fetchColumn();
$valor_esperado = (float) $caixa['valor_inicial'] + $total_dinheiro;

$resumo = $pdo->prepare(
    "SELECT u.nome, COUNT(v.id_venda) AS qtd_vendas, COALESCE(SUM(v.valor_total), 0) AS total_vendido
     FROM vendas v
     JOIN usuarios u ON u.id_usuario = v.id_usuario
     WHERE v.id_caixa = :ic AND v.status = 'Pago'
     GROUP BY u.id_usuario, u.nome
     ORDER BY u.nome"
);
$resumo->execute([':ic' => $caixa['id_caixa']]);
$resumoOperadores = $resumo->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Fechar caixa</title></head>
<body>
    <h1>Fechar caixa</h1>
    <p>Valor inicial: R$ <?= number_format($caixa['valor_inicial'], 2, ',', '.') ?></p>
    <p>Vendas em dinheiro: R$ <?= number_format($total_dinheiro, 2, ',', '.') ?></p>
    <p>Esperado no caixa: R$ <?= number_format($valor_esperado, 2, ',', '.') ?></p>

    <h3>Resumo por operador</h3>
    <table border="1" cellpadding="6">
        <tr><th>Operador</th><th>Vendas</th><th>Total vendido</th></tr>
        <?php foreach ($resumoOperadores as $r): ?>
        <tr>
            <td><?= htmlspecialchars($r['nome']) ?></td>
            <td><?= (int) $r['qtd_vendas'] ?></td>
            <td>R$ <?= number_format($r['total_vendido'], 2, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <form method="post" action="/caixa/ajax/fechar_caixa.php">
        <label>Valor contado no caixa (R$)<br><input type="text" name="valor_final_informado" required></label><br>
        <label>Observação<br><textarea name="observacao_fechamento"></textarea></label><br>
        <button type="submit">Fechar caixa</button>
    </form>
</body>
</html>
