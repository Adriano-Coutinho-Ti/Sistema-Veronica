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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Fechar caixa</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Fechar caixa</h1>
            <span class="subtitulo">Aberto em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($caixa['data_abertura']))) ?></span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/caixa/index.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar ao PDV
        </a>
    </p>

    <div class="card">
        <h2>Resumo do caixa</h2>
        <div class="stats-credito">
            <div class="stat-credito">
                <span class="stat-label">Valor inicial</span>
                <span class="stat-valor">R$ <?= number_format($caixa['valor_inicial'], 2, ',', '.') ?></span>
            </div>
            <div class="stat-credito">
                <span class="stat-label">Vendas em dinheiro</span>
                <span class="stat-valor">R$ <?= number_format($total_dinheiro_vendas, 2, ',', '.') ?></span>
            </div>
            <div class="stat-credito">
                <span class="stat-label">Dívidas pagas em dinheiro</span>
                <span class="stat-valor">R$ <?= number_format($total_dinheiro_divida, 2, ',', '.') ?></span>
            </div>
            <div class="stat-credito">
                <span class="stat-label">Esperado no caixa</span>
                <span class="stat-valor sucesso">R$ <?= number_format($valor_esperado, 2, ',', '.') ?></span>
            </div>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
        <h2>Resumo por operador</h2>
        <?php if (empty($resumoOperadores)): ?>
        <p class="alert alert-info">Nenhuma venda registrada nesta sessão de caixa.</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Operador</th><th>Vendas</th><th>Total vendido</th></tr>
            <?php foreach ($resumoOperadores as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['nome']) ?></td>
                <td><?= (int) $r['qtd_vendas'] ?></td>
                <td>R$ <?= number_format($r['total_vendido'], 2, ',', '.') ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:20px;">
        <h2>Conferência e fechamento</h2>
        <form method="post" action="/caixa/ajax/fechar_caixa.php" data-confirm="Fechar o caixa? Depois de fechado não dá pra registrar mais vendas nesta sessão.">
            <label>Valor contado no caixa (R$)<input type="text" name="valor_final_informado" required></label>
            <label>Observação<textarea name="observacao_fechamento"></textarea></label>
            <button type="submit" class="btn-bloco">Fechar caixa</button>
        </form>
    </div>
</main>
</body>
</html>
