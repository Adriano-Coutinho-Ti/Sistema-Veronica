<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirLogin();

$busca = trim($_GET['busca'] ?? '');
$filtro = $_GET['status_entrega'] ?? '';
$filtroValido = $filtro === 'cancelados' || in_array($filtro, STATUS_ENTREGA_VALIDOS, true) ? $filtro : '';

// Cancelado só aparece quando o filtro "Cancelados" é escolhido de propósito
// — a lista principal (sem filtro) mostra só os pedidos pagos, pra não
// misturar pedido ativo com pedido cancelado na mesma tela.
$where = "v.origem = 'loja'";
$params = [];
if ($filtroValido === 'cancelados') {
    $where .= " AND v.status = 'Cancelado'";
} elseif ($filtroValido !== '') {
    $where .= " AND v.status = 'Pago' AND v.status_entrega = :se";
    $params[':se'] = $filtroValido;
} else {
    $where .= " AND v.status = 'Pago'";
}
if ($busca !== '') {
    $where .= ' AND (c.nome LIKE :busca OR v.id_venda = :buscaId)';
    $params[':busca'] = '%' . $busca . '%';
    $params[':buscaId'] = (int) $busca;
}

const PEDIDOS_POR_PAGINA = 20;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotal = $pdo->prepare(
    "SELECT COUNT(*) FROM vendas v LEFT JOIN clientes c ON c.id_cliente = v.id_cliente WHERE $where"
);
$stmtTotal->execute($params);
$totalPedidos = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalPedidos / PEDIDOS_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * PEDIDOS_POR_PAGINA;

$stmt = $pdo->prepare(
    "SELECT v.id_venda, v.data_venda, v.valor_total, v.status, v.status_entrega, c.nome AS cliente_nome
     FROM vendas v
     LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
     WHERE $where
     ORDER BY v.data_venda DESC
     LIMIT :limite OFFSET :offset"
);
foreach ($params as $chave => $valor) {
    $stmt->bindValue($chave, $valor);
}
$stmt->bindValue(':limite', PEDIDOS_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$pedidos = $stmt->fetchAll();

function montarLinkFiltroPedidos(int $pagina, string $status, string $busca): string
{
    $params = ['pagina' => $pagina];
    if ($status !== '') {
        $params['status_entrega'] = $status;
    }
    if ($busca !== '') {
        $params['busca'] = $busca;
    }
    return '/pedidos/lista.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pedidos</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16l-1.5 12.5a2 2 0 0 1-2 1.5H7.5a2 2 0 0 1-2-1.5L4 7Zm3 0V5a3 3 0 0 1 6 0v2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Pedidos</h1>
            <span class="subtitulo"><?= $totalPedidos ?> pedido<?= $totalPedidos === 1 ? '' : 's' ?> da loja online</span>
        </div>
    </div>

    <div class="layout-lateral">
        <aside class="filtros-lateral">
            <h2>Etapa</h2>
            <nav>
                <a href="<?= montarLinkFiltroPedidos(1, '', $busca) ?>" class="<?= $filtroValido === '' ? 'ativa' : '' ?>">Todas</a>
                <?php foreach (STATUS_ENTREGA_VALIDOS as $s): ?>
                <a href="<?= montarLinkFiltroPedidos(1, $s, $busca) ?>" class="<?= $filtroValido === $s ? 'ativa' : '' ?>"><?= htmlspecialchars($s) ?></a>
                <?php endforeach; ?>
                <a href="<?= montarLinkFiltroPedidos(1, 'cancelados', $busca) ?>" class="<?= $filtroValido === 'cancelados' ? 'ativa' : '' ?>">Cancelados</a>
            </nav>
        </aside>

        <div>
            <div class="filtros-mobile">
                <a href="<?= montarLinkFiltroPedidos(1, '', $busca) ?>" class="filtro-pill<?= $filtroValido === '' ? ' ativa' : '' ?>">Todas</a>
                <?php foreach (STATUS_ENTREGA_VALIDOS as $s): ?>
                <a href="<?= montarLinkFiltroPedidos(1, $s, $busca) ?>" class="filtro-pill<?= $filtroValido === $s ? ' ativa' : '' ?>"><?= htmlspecialchars($s) ?></a>
                <?php endforeach; ?>
                <a href="<?= montarLinkFiltroPedidos(1, 'cancelados', $busca) ?>" class="filtro-pill<?= $filtroValido === 'cancelados' ? ' ativa' : '' ?>">Cancelados</a>
            </div>

            <form method="get" class="busca-lista">
                <?php if ($filtroValido !== ''): ?><input type="hidden" name="status_entrega" value="<?= htmlspecialchars($filtroValido) ?>"><?php endif; ?>
                <input type="text" name="busca" placeholder="Buscar por cliente ou nº do pedido..." value="<?= htmlspecialchars($busca) ?>">
                <button type="submit">Buscar</button>
            </form>

            <?php if (empty($pedidos)): ?>
                <p class="alert alert-info">Nenhum pedido encontrado.</p>
            <?php else: ?>
            <div class="alternador-visualizacao" data-chave="pedidos" data-alvo-lista="visualizacao-lista" data-alvo-cards="visualizacao-cards">
                <button type="button" class="btn-sm btn-outline" data-modo="lista" title="Ver em lista">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                </button>
                <button type="button" class="btn-sm btn-outline" data-modo="cards" title="Ver em cards">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </button>
            </div>

            <div id="visualizacao-lista">
            <div class="tabela-wrap">
            <table>
                <tr><th>Pedido</th><th>Cliente</th><th>Data</th><th>Total</th><th>Status</th><th></th></tr>
                <?php foreach ($pedidos as $p): ?>
                <tr>
                    <td>#<?= (int) $p['id_venda'] ?></td>
                    <td><?= htmlspecialchars($p['cliente_nome'] ?? '—') ?></td>
                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($p['data_venda']))) ?></td>
                    <td>R$ <?= number_format($p['valor_total'], 2, ',', '.') ?></td>
                    <td><span class="status-pill <?= classePillStatusPedido($p['status'], $p['status_entrega']) ?>"><?= htmlspecialchars(rotuloStatusPedido($p['status'], $p['status_entrega'])) ?></span></td>
                    <td><a href="/pedidos/detalhe.php?id_venda=<?= (int) $p['id_venda'] ?>" class="btn-sm btn-outline">Ver</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
            </div>

            <div id="visualizacao-cards" hidden>
            <div class="grade-cards">
                <?php foreach ($pedidos as $p): ?>
                <div class="item-card">
                    <div class="item-card-topo">
                        <strong>#<?= (int) $p['id_venda'] ?></strong>
                        <span class="status-pill <?= classePillStatusPedido($p['status'], $p['status_entrega']) ?>"><?= htmlspecialchars(rotuloStatusPedido($p['status'], $p['status_entrega'])) ?></span>
                    </div>
                    <p><?= htmlspecialchars($p['cliente_nome'] ?? '—') ?></p>
                    <p><?= htmlspecialchars(date('d/m/Y H:i', strtotime($p['data_venda']))) ?></p>
                    <p>R$ <?= number_format($p['valor_total'], 2, ',', '.') ?></p>
                    <div class="celula-acoes">
                        <a href="/pedidos/detalhe.php?id_venda=<?= (int) $p['id_venda'] ?>" class="btn-sm btn-outline">Ver</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </div>

            <?php if ($totalPaginas > 1): ?>
            <nav class="paginacao">
                <?php if ($pagina > 1): ?><a href="<?= montarLinkFiltroPedidos($pagina - 1, $filtroValido, $busca) ?>">‹ Anterior</a><?php endif; ?>
                <?php for ($p2 = 1; $p2 <= $totalPaginas; $p2++): ?>
                    <a href="<?= montarLinkFiltroPedidos($p2, $filtroValido, $busca) ?>" class="<?= $p2 === $pagina ? 'ativa' : '' ?>"><?= $p2 ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkFiltroPedidos($pagina + 1, $filtroValido, $busca) ?>">Próxima ›</a><?php endif; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
