<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/auth.php';
exigirLogin();

$mpNaoConectado = false;
if (($_SESSION['perfil'] ?? '') === 'Admin') {
    $configPagamento = $pdo->query('SELECT mp_access_token FROM config_pagamento WHERE id_config = 1')->fetch();
    $mpNaoConectado = empty($configPagamento['mp_access_token']);
}

$vendasHoje = $pdo->query(
    "SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_total), 0) AS total FROM vendas WHERE DATE(data_venda) = CURDATE() AND status = 'Pago'"
)->fetch();

$clientesDevedores = $pdo->query(
    'SELECT COUNT(*) AS qtd, COALESCE(SUM(saldo_devedor), 0) AS total FROM clientes WHERE saldo_devedor > 0'
)->fetch();

$produtosEsgotados = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
        SELECT p.id_produto FROM produtos p
        JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE p.estoque_gerenciado = 1 AND p.ativo = 1
        GROUP BY p.id_produto
        HAVING SUM(pv.estoque) = 0
     ) t"
)->fetchColumn();

$pedidosAguardando = (int) $pdo->query(
    "SELECT COUNT(*) FROM vendas WHERE origem = 'loja' AND status = 'Pago' AND status_entrega = 'Aguardando preparo'"
)->fetchColumn();

// Vendas dos últimos 10 dias (barras) — preenche os dias sem venda com 0 pra
// nunca ter um "buraco" no gráfico.
$stmtVendasDias = $pdo->query(
    "SELECT DATE(data_venda) AS dia, SUM(valor_total) AS total
     FROM vendas
     WHERE status = 'Pago' AND data_venda >= DATE_SUB(CURDATE(), INTERVAL 9 DAY)
     GROUP BY DATE(data_venda)"
);
$vendasPorDia = [];
foreach ($stmtVendasDias->fetchAll() as $linha) {
    $vendasPorDia[$linha['dia']] = (float) $linha['total'];
}
$labelsDias = [];
$valoresDias = [];
for ($i = 9; $i >= 0; $i--) {
    $data = date('Y-m-d', strtotime("-{$i} days"));
    $labelsDias[] = $i === 0 ? 'Hoje' : date('d/m', strtotime($data));
    $valoresDias[] = $vendasPorDia[$data] ?? 0;
}

// Vendas por forma de pagamento nos últimos 30 dias — soma por venda_pagamentos
// (não por vendas.forma_pagamento) pra uma venda "Mista" contar o valor certo
// em cada forma, em vez de virar um balde genérico "Mista".
$stmtFormas = $pdo->query(
    "SELECT vp.forma_pagamento, SUM(vp.valor) AS total
     FROM venda_pagamentos vp
     JOIN vendas v ON v.id_venda = vp.id_venda
     WHERE v.status = 'Pago' AND v.data_venda >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY vp.forma_pagamento
     ORDER BY total DESC"
);
$formasPagamento = $stmtFormas->fetchAll();
$labelsFormas = array_column($formasPagamento, 'forma_pagamento');
$valoresFormas = array_map('floatval', array_column($formasPagamento, 'total'));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Dashboard</title></head>
<body>
<?php require __DIR__ . '/includes/admin_header.php'; ?>
    <?php if ($mpNaoConectado): ?>
    <p class="alert alert-erro">
        A conta do Mercado Pago não está conectada — a loja online e o PDV não conseguem receber pagamentos via Pix/cartão até conectar.
        <a href="/integracoes/mercado_pago/conectar.php">Conectar agora</a>
    </p>
    <?php endif; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19h16M6 19V9l5-4 5 4v10M10 19v-5h4v5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Dashboard</h1>
            <span class="subtitulo">Olá, <?= htmlspecialchars($_SESSION['nome'] ?? '') ?> — aqui está o panorama de hoje</span>
        </div>
    </div>

    <div class="stats-credito">
        <div class="stat-credito">
            <span class="stat-label">Vendas hoje</span>
            <span class="stat-valor">R$ <?= number_format((float) $vendasHoje['total'], 2, ',', '.') ?></span>
            <span class="stat-obs"><?= (int) $vendasHoje['qtd'] ?> venda<?= (int) $vendasHoje['qtd'] === 1 ? '' : 's' ?></span>
        </div>
        <a href="/clientes/devedores.php" class="stat-credito">
            <span class="stat-label">Clientes devedores</span>
            <span class="stat-valor<?= (float) $clientesDevedores['total'] > 0 ? ' erro' : '' ?>">R$ <?= number_format((float) $clientesDevedores['total'], 2, ',', '.') ?></span>
            <span class="stat-obs"><?= (int) $clientesDevedores['qtd'] ?> cliente<?= (int) $clientesDevedores['qtd'] === 1 ? '' : 's' ?></span>
        </a>
        <a href="/produtos/lista.php?status=ativos" class="stat-credito">
            <span class="stat-label">Produtos esgotados</span>
            <span class="stat-valor<?= $produtosEsgotados > 0 ? ' erro' : '' ?>"><?= $produtosEsgotados ?></span>
            <span class="stat-obs">com estoque controlado</span>
        </a>
        <a href="/pedidos/lista.php" class="stat-credito">
            <span class="stat-label">Pedidos aguardando preparo</span>
            <span class="stat-valor<?= $pedidosAguardando > 0 ? ' alerta' : '' ?>"><?= $pedidosAguardando ?></span>
            <span class="stat-obs">na loja online</span>
        </a>
    </div>

    <div class="grade-2col" style="margin-top:20px;">
        <div class="card">
            <h2>Vendas dos últimos 10 dias</h2>
            <canvas id="grafico-vendas-dias" height="220"></canvas>
        </div>
        <div class="card">
            <h2>Vendas por forma de pagamento (30 dias)</h2>
            <?php if (empty($labelsFormas)): ?>
            <p class="alert alert-info">Nenhuma venda nos últimos 30 dias ainda.</p>
            <?php else: ?>
            <canvas id="grafico-formas-pagamento" height="220"></canvas>
            <?php endif; ?>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const corPrimaria = '#4F46E5';
    const paletaFormas = ['#4F46E5', '#16A34A', '#DC2626', '#F59E0B', '#0EA5E9', '#9333EA'];

    new Chart(document.getElementById('grafico-vendas-dias'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($labelsDias, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                label: 'Vendas (R$)',
                data: <?= json_encode($valoresDias) ?>,
                backgroundColor: corPrimaria,
                borderRadius: 4,
            }],
        },
        options: {
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return 'R$ ' + v; } } } },
        },
    });

    <?php if (!empty($labelsFormas)): ?>
    new Chart(document.getElementById('grafico-formas-pagamento'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($labelsFormas, JSON_UNESCAPED_UNICODE) ?>,
            datasets: [{
                data: <?= json_encode($valoresFormas) ?>,
                backgroundColor: paletaFormas,
                borderWidth: 0,
            }],
        },
        options: {
            cutout: '70%',
            plugins: {
                legend: { position: 'right' },
                tooltip: {
                    callbacks: {
                        label: function (contexto) {
                            const valor = contexto.parsed.toFixed(2).replace('.', ',');
                            return contexto.label + ': R$ ' + valor;
                        },
                    },
                },
            },
        },
    });
    <?php endif; ?>
});
</script>
</main>
</body>
</html>
