<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

const FORMAS_PAGAMENTO_VENDA = ['Dinheiro', 'Débito', 'Crédito', 'Pix', 'Linha de Crédito', 'Mista'];

$busca = trim($_GET['busca'] ?? '');
$dataFiltro = trim($_GET['data'] ?? '');
$formaFiltro = $_GET['forma'] ?? '';
if (!in_array($formaFiltro, FORMAS_PAGAMENTO_VENDA, true)) {
    $formaFiltro = '';
}
$operadorFiltro = (int) ($_GET['operador'] ?? 0);

$operadores = $pdo->query(
    "SELECT DISTINCT u.id_usuario, u.nome
     FROM usuarios u
     JOIN vendas v ON v.id_usuario = u.id_usuario
     WHERE v.origem = 'pdv' AND v.status = 'Pago'
     ORDER BY u.nome"
)->fetchAll();

$where = "v.origem = 'pdv' AND v.status = 'Pago'";
$params = [];
if ($busca !== '') {
    $where .= ' AND (c.nome LIKE :busca OR v.id_venda = :buscaId)';
    $params[':busca'] = '%' . $busca . '%';
    $params[':buscaId'] = $busca;
}
if ($dataFiltro !== '') {
    $where .= ' AND DATE(v.data_venda) = :data';
    $params[':data'] = $dataFiltro;
}
if ($formaFiltro !== '') {
    $where .= ' AND v.forma_pagamento = :forma';
    $params[':forma'] = $formaFiltro;
}
if ($operadorFiltro > 0) {
    $where .= ' AND v.id_usuario = :operador';
    $params[':operador'] = $operadorFiltro;
}

const VENDAS_POR_PAGINA = 20;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotal = $pdo->prepare(
    "SELECT COUNT(*) FROM vendas v LEFT JOIN clientes c ON c.id_cliente = v.id_cliente WHERE $where"
);
$stmtTotal->execute($params);
$totalVendas = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalVendas / VENDAS_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * VENDAS_POR_PAGINA;

$stmt = $pdo->prepare(
    "SELECT v.id_venda, v.data_venda, v.valor_total, v.forma_pagamento, u.nome AS operador_nome, c.nome AS cliente_nome
     FROM vendas v
     JOIN usuarios u ON u.id_usuario = v.id_usuario
     LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
     WHERE $where
     ORDER BY v.data_venda DESC
     LIMIT :limite OFFSET :offset"
);
foreach ($params as $chave => $valor) {
    $stmt->bindValue($chave, $valor);
}
$stmt->bindValue(':limite', VENDAS_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$vendas = $stmt->fetchAll();

function montarLinkFiltroVendas(int $pagina, string $busca, string $data, string $forma, int $operador): string
{
    $params = ['pagina' => $pagina];
    if ($busca !== '') {
        $params['busca'] = $busca;
    }
    if ($data !== '') {
        $params['data'] = $data;
    }
    if ($forma !== '') {
        $params['forma'] = $forma;
    }
    if ($operador > 0) {
        $params['operador'] = $operador;
    }
    return '/caixa/vendas.php?' . http_build_query($params);
}

$temFiltroAtivo = $busca !== '' || $dataFiltro !== '' || $formaFiltro !== '' || $operadorFiltro > 0;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Vendas do caixa</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Vendas do caixa</h1>
            <span class="subtitulo"><?= $totalVendas ?> venda<?= $totalVendas === 1 ? '' : 's' ?> feita<?= $totalVendas === 1 ? '' : 's' ?> no PDV</span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/caixa/index.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar ao PDV
        </a>
    </p>

    <div class="card">
        <form method="get" class="form-linha-compacta" style="margin-bottom:18px;">
            <input type="text" name="busca" placeholder="Cliente ou nº da venda..." value="<?= htmlspecialchars($busca) ?>">
            <input type="date" name="data" value="<?= htmlspecialchars($dataFiltro) ?>">
            <select name="forma">
                <option value="">Todas as formas</option>
                <?php foreach (FORMAS_PAGAMENTO_VENDA as $f): ?>
                    <option value="<?= htmlspecialchars($f) ?>" <?= $formaFiltro === $f ? 'selected' : '' ?>><?= htmlspecialchars($f) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="operador">
                <option value="0">Todos os operadores</option>
                <?php foreach ($operadores as $op): ?>
                    <option value="<?= (int) $op['id_usuario'] ?>" <?= $operadorFiltro === (int) $op['id_usuario'] ? 'selected' : '' ?>><?= htmlspecialchars($op['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filtrar</button>
            <?php if ($temFiltroAtivo): ?><a href="/caixa/vendas.php" class="btn-outline btn-sm">Limpar filtros</a><?php endif; ?>
        </form>

        <?php if (empty($vendas)): ?>
        <p class="alert alert-info">Nenhuma venda encontrada.</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Venda</th><th>Cliente</th><th>Data</th><th>Total</th><th>Pagamento</th><th>Operador</th><th></th></tr>
            <?php foreach ($vendas as $v): ?>
            <tr>
                <td>#<?= (int) $v['id_venda'] ?></td>
                <td><?= htmlspecialchars($v['cliente_nome'] ?? 'Consumidor') ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($v['data_venda']))) ?></td>
                <td>R$ <?= number_format($v['valor_total'], 2, ',', '.') ?></td>
                <td><?= htmlspecialchars($v['forma_pagamento'] ?? '—') ?></td>
                <td><?= htmlspecialchars($v['operador_nome']) ?></td>
                <td><a href="/caixa/comprovante.php?id_venda=<?= (int) $v['id_venda'] ?>" class="btn-sm btn-outline">Ver</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>

        <?php if ($totalPaginas > 1): ?>
        <nav class="paginacao">
            <?php if ($pagina > 1): ?><a href="<?= montarLinkFiltroVendas($pagina - 1, $busca, $dataFiltro, $formaFiltro, $operadorFiltro) ?>">‹ Anterior</a><?php endif; ?>
            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                <a href="<?= montarLinkFiltroVendas($p, $busca, $dataFiltro, $formaFiltro, $operadorFiltro) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkFiltroVendas($pagina + 1, $busca, $dataFiltro, $formaFiltro, $operadorFiltro) ?>">Próxima ›</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
