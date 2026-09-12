<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$filtro = $_GET['status'] ?? '';
$filtrosValidos = array_merge(['todos', 'Cancelado'], STATUS_ENTREGA_VALIDOS);
if (!in_array($filtro, $filtrosValidos, true)) {
    $filtro = '';
}

$where = 'v.id_cliente = :ic AND v.origem = \'loja\'';
$params = [':ic' => $id_cliente];
if ($filtro === '') {
    // Padrão: só pedidos ativos, sem os cancelados atrapalhando a visão geral.
    $where .= ' AND v.status = \'Pago\'';
} elseif ($filtro === 'todos') {
    $where .= ' AND v.status IN (\'Pago\', \'Cancelado\')';
} elseif ($filtro === 'Cancelado') {
    $where .= ' AND v.status = \'Cancelado\'';
} else {
    $where .= ' AND v.status = \'Pago\' AND v.status_entrega = :se';
    $params[':se'] = $filtro;
}

const PEDIDOS_POR_PAGINA = 9;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM vendas v WHERE $where");
$stmtTotal->execute($params);
$totalPedidos = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalPedidos / PEDIDOS_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * PEDIDOS_POR_PAGINA;

$stmt = $pdo->prepare(
    "SELECT v.id_venda, v.data_venda, v.valor_total, v.status, v.status_entrega, fe.tipo AS entrega_tipo,
            (SELECT pf.caminho_arquivo
             FROM itens_venda iv
             JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
             JOIN produto_fotos pf ON pf.id_produto = pv.id_produto
             WHERE iv.id_venda = v.id_venda
             ORDER BY iv.id_item, pf.ordem LIMIT 1) AS foto
     FROM vendas v
     LEFT JOIN formas_entrega fe ON fe.id_entrega = v.id_entrega
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

function montarLinkFiltro(string $status, int $pagina = 1): string
{
    $params = ['pagina' => $pagina];
    if ($status !== '') {
        $params['status'] = $status;
    }
    return '/loja/meus_pedidos.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Meus pedidos</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="banner-hero">
        <h1>Meus pedidos</h1>
        <p>Acompanhe o status de tudo que você já comprou.</p>
    </div>

    <div class="catalogo-layout">
        <aside class="categorias-lateral">
            <h2>Filtrar por status</h2>
            <nav>
                <a href="<?= montarLinkFiltro('') ?>" class="<?= $filtro === '' ? 'ativa' : '' ?>">Ativos</a>
                <a href="<?= montarLinkFiltro('todos') ?>" class="<?= $filtro === 'todos' ? 'ativa' : '' ?>">Todos</a>
                <?php foreach (STATUS_ENTREGA_VALIDOS as $s): ?>
                    <a href="<?= montarLinkFiltro($s) ?>" class="<?= $filtro === $s ? 'ativa' : '' ?>"><?= htmlspecialchars($s) ?></a>
                <?php endforeach; ?>
                <a href="<?= montarLinkFiltro('Cancelado') ?>" class="<?= $filtro === 'Cancelado' ? 'ativa' : '' ?>">Cancelado</a>
            </nav>
        </aside>

        <div>
            <div class="categorias">
                <a href="<?= montarLinkFiltro('') ?>" class="categoria-pill<?= $filtro === '' ? ' ativa' : '' ?>">Ativos</a>
                <a href="<?= montarLinkFiltro('todos') ?>" class="categoria-pill<?= $filtro === 'todos' ? ' ativa' : '' ?>">Todos</a>
                <?php foreach (STATUS_ENTREGA_VALIDOS as $s): ?>
                    <a href="<?= montarLinkFiltro($s) ?>" class="categoria-pill<?= $filtro === $s ? ' ativa' : '' ?>"><?= htmlspecialchars($s) ?></a>
                <?php endforeach; ?>
                <a href="<?= montarLinkFiltro('Cancelado') ?>" class="categoria-pill<?= $filtro === 'Cancelado' ? ' ativa' : '' ?>">Cancelado</a>
            </div>

            <?php if (empty($pedidos)): ?>
                <p>Nenhum pedido encontrado. <a href="/loja/index.php">Ver catálogo</a></p>
            <?php else: ?>
            <div class="pedidos-grid">
                <?php foreach ($pedidos as $p): ?>
                    <?php
                        $rotulo = rotuloStatusPedido($p['status'], $p['status_entrega'], $p['entrega_tipo']);
                        $classePill = classePillStatusPedido($p['status'], $p['status_entrega']);
                    ?>
                    <a href="/loja/pedido_status.php?id_venda=<?= (int) $p['id_venda'] ?>" class="pedido-card">
                        <div class="foto">
                            <?php if ($p['foto']): ?>
                                <img src="/<?= htmlspecialchars($p['foto']) ?>" alt="Pedido #<?= (int) $p['id_venda'] ?>">
                            <?php else: ?>
                                <img src="/assets/img/produto-indisponivel.svg" alt="Produto indisponível">
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="numero">Pedido #<?= (int) $p['id_venda'] ?></div>
                            <div class="data"><?= htmlspecialchars(date('d/m/Y', strtotime($p['data_venda']))) ?></div>
                            <div class="total">R$ <?= number_format($p['valor_total'], 2, ',', '.') ?></div>
                            <span class="status-pill<?= $classePill ? ' ' . $classePill : '' ?>"><?= htmlspecialchars($rotulo) ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPaginas > 1): ?>
            <nav class="paginacao">
                <?php if ($pagina > 1): ?><a href="<?= montarLinkFiltro($filtro, $pagina - 1) ?>">‹ Anterior</a><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                    <a href="<?= montarLinkFiltro($filtro, $p) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkFiltro($filtro, $pagina + 1) ?>">Próxima ›</a><?php endif; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
