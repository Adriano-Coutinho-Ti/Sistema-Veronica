<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$id_caixa = (int) ($_GET['id_caixa'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT cs.*, ua.nome AS aberto_por_nome, uf.nome AS fechado_por_nome
     FROM caixa_sessoes cs
     JOIN usuarios ua ON ua.id_usuario = cs.aberto_por
     LEFT JOIN usuarios uf ON uf.id_usuario = cs.fechado_por
     WHERE cs.id_caixa = :id"
);
$stmt->execute([':id' => $id_caixa]);
$caixa = $stmt->fetch();

if (!$caixa) {
    http_response_code(404);
    echo 'Caixa não encontrado.';
    exit;
}

// Só as vendas feitas de balcão (origem='pdv') — vendas.id_caixa é
// preenchido pra toda venda, inclusive as da loja online que passaram a
// existir enquanto esse caixa estava aberto, mas essas não fazem parte da
// "sessão de caixa" no sentido físico que essa tela mostra.
$vendas = $pdo->prepare(
    "SELECT v.id_venda, v.data_venda, v.valor_total, v.status, v.forma_pagamento, c.nome AS cliente_nome
     FROM vendas v
     LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
     WHERE v.id_caixa = :id AND v.origem = 'pdv' AND v.status = 'Pago'
     ORDER BY v.data_venda"
);
$vendas->execute([':id' => $id_caixa]);
$listaVendas = $vendas->fetchAll();

$totalVendido = array_sum(array_column($listaVendas, 'valor_total'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Caixa #<?= $id_caixa ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Caixa #<?= $id_caixa ?></h1>
            <span class="subtitulo">Aberto por <?= htmlspecialchars($caixa['aberto_por_nome']) ?> em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($caixa['data_abertura']))) ?></span>
        </div>
        <span class="status-pill<?= $caixa['status'] === 'aberto' ? '' : ' sucesso' ?>"><?= $caixa['status'] === 'aberto' ? 'Aberto' : 'Fechado' ?></span>
    </div>

    <p class="acoes-topo">
        <a href="/caixa/historico.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar ao histórico
        </a>
    </p>

    <div class="card">
        <h2>Resumo</h2>
        <div class="stats-credito">
            <div class="stat-credito">
                <span class="stat-label">Valor inicial</span>
                <span class="stat-valor">R$ <?= number_format($caixa['valor_inicial'], 2, ',', '.') ?></span>
            </div>
            <div class="stat-credito">
                <span class="stat-label">Vendido no PDV</span>
                <span class="stat-valor">R$ <?= number_format($totalVendido, 2, ',', '.') ?></span>
            </div>
            <?php if ($caixa['status'] === 'fechado'): ?>
            <div class="stat-credito">
                <span class="stat-label">Informado no fechamento</span>
                <span class="stat-valor">R$ <?= number_format($caixa['valor_final_informado'], 2, ',', '.') ?></span>
            </div>
            <div class="stat-credito">
                <span class="stat-label">Diferença</span>
                <span class="stat-valor<?= (float) $caixa['diferenca'] !== 0.0 ? ' erro' : ' sucesso' ?>">R$ <?= number_format($caixa['diferenca'], 2, ',', '.') ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($caixa['status'] === 'fechado'): ?>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:14px;">
            Fechado por <?= htmlspecialchars($caixa['fechado_por_nome'] ?? '—') ?> em <?= htmlspecialchars(date('d/m/Y H:i', strtotime($caixa['data_fechamento']))) ?>
            <?php if (!empty($caixa['observacao_fechamento'])): ?><br>Obs: <?= htmlspecialchars($caixa['observacao_fechamento']) ?><?php endif; ?>
        </p>
        <?php endif; ?>
    </div>

    <div class="card" style="margin-top:20px;">
        <h2>Vendas desta sessão</h2>
        <?php if (empty($listaVendas)): ?>
        <p class="alert alert-info">Nenhuma venda de balcão registrada nesta sessão.</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Venda</th><th>Cliente</th><th>Data</th><th>Total</th><th>Pagamento</th><th></th></tr>
            <?php foreach ($listaVendas as $v): ?>
            <tr>
                <td>#<?= (int) $v['id_venda'] ?></td>
                <td><?= htmlspecialchars($v['cliente_nome'] ?? 'Consumidor') ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($v['data_venda']))) ?></td>
                <td>R$ <?= number_format($v['valor_total'], 2, ',', '.') ?></td>
                <td><?= htmlspecialchars($v['forma_pagamento'] ?? '—') ?></td>
                <td><a href="/caixa/comprovante.php?id_venda=<?= (int) $v['id_venda'] ?>" class="btn-sm btn-outline">Ver</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
