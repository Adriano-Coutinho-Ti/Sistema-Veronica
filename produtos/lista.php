<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
exigirLogin();

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();

$mpNaoConectado = false;
if (($_SESSION['perfil'] ?? '') === 'Admin') {
    $configPagamento = $pdo->query('SELECT mp_access_token FROM config_pagamento WHERE id_config = 1')->fetch();
    $mpNaoConectado = empty($configPagamento['mp_access_token']);
}

$busca = trim($_GET['busca'] ?? '');
$id_categoria = (int) ($_GET['categoria'] ?? 0);
$statusFiltro = $_GET['status'] ?? '';
if (!in_array($statusFiltro, ['ativos', 'inativos'], true)) {
    $statusFiltro = '';
}

$where = '1=1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (p.nome LIKE :busca OR p.codigo = :buscaCodigo)';
    $params[':busca'] = '%' . $busca . '%';
    $params[':buscaCodigo'] = $busca;
}
if ($id_categoria > 0) {
    $where .= ' AND p.id_categoria = :ic';
    $params[':ic'] = $id_categoria;
}
if ($statusFiltro === 'ativos') {
    $where .= ' AND p.ativo = 1';
} elseif ($statusFiltro === 'inativos') {
    $where .= ' AND p.ativo = 0';
}

const PRODUTOS_POR_PAGINA = 15;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM produtos p WHERE $where");
$stmtTotal->execute($params);
$totalProdutos = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalProdutos / PRODUTOS_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * PRODUTOS_POR_PAGINA;

$sql = "SELECT p.id_produto, p.nome, p.codigo, p.preco_base, p.ativo, c.nome AS categoria,
               COALESCE(SUM(pv.estoque), 0) AS estoque_total,
               (SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = p.id_produto ORDER BY ordem LIMIT 1) AS foto
        FROM produtos p
        JOIN categorias c ON c.id_categoria = p.id_categoria
        LEFT JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE $where
        GROUP BY p.id_produto, p.nome, p.codigo, p.preco_base, p.ativo, c.nome
        ORDER BY p.nome ASC
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $chave => $valor) {
    $stmt->bindValue($chave, $valor);
}
$stmt->bindValue(':limite', PRODUTOS_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produtos = $stmt->fetchAll();

function montarLinkFiltroProdutos(int $pagina, int $categoria, string $status, string $busca): string
{
    $params = ['pagina' => $pagina];
    if ($categoria > 0) {
        $params['categoria'] = $categoria;
    }
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($busca !== '') {
        $params['busca'] = $busca;
    }
    return '/produtos/lista.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Produtos</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <?php if ($mpNaoConectado): ?>
    <p class="alert alert-erro">
        A conta do Mercado Pago não está conectada — a loja online e o PDV não conseguem receber pagamentos via Pix/cartão até conectar.
        <a href="/integracoes/mercado_pago/conectar.php">Conectar agora</a>
    </p>
    <?php endif; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.8h7.2a2 2 0 0 0 2-1.6L20 8H6M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Produtos</h1>
            <span class="subtitulo"><?= $totalProdutos ?> produto<?= $totalProdutos === 1 ? '' : 's' ?> cadastrado<?= $totalProdutos === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Produto criado com sucesso.</p><?php endif; ?>
    <p><a href="/produtos/novo.php" class="btn">+ Novo produto</a> <a href="/produtos/categorias.php" class="btn-outline">Categorias</a></p>

    <div class="layout-lateral">
        <aside class="filtros-lateral">
            <h2>Categoria</h2>
            <nav>
                <a href="<?= montarLinkFiltroProdutos(1, 0, $statusFiltro, $busca) ?>" class="<?= $id_categoria === 0 ? 'ativa' : '' ?>">Todas</a>
                <?php foreach ($categorias as $c): ?>
                    <a href="<?= montarLinkFiltroProdutos(1, (int) $c['id_categoria'], $statusFiltro, $busca) ?>" class="<?= $id_categoria === (int) $c['id_categoria'] ? 'ativa' : '' ?>"><?= htmlspecialchars($c['nome']) ?></a>
                <?php endforeach; ?>
            </nav>
            <h2>Status</h2>
            <nav>
                <a href="<?= montarLinkFiltroProdutos(1, $id_categoria, '', $busca) ?>" class="<?= $statusFiltro === '' ? 'ativa' : '' ?>">Todos</a>
                <a href="<?= montarLinkFiltroProdutos(1, $id_categoria, 'ativos', $busca) ?>" class="<?= $statusFiltro === 'ativos' ? 'ativa' : '' ?>">Ativos</a>
                <a href="<?= montarLinkFiltroProdutos(1, $id_categoria, 'inativos', $busca) ?>" class="<?= $statusFiltro === 'inativos' ? 'ativa' : '' ?>">Inativos</a>
            </nav>
        </aside>

        <div>
            <div class="filtros-mobile">
                <a href="<?= montarLinkFiltroProdutos(1, 0, $statusFiltro, $busca) ?>" class="filtro-pill<?= $id_categoria === 0 ? ' ativa' : '' ?>">Todas categorias</a>
                <?php foreach ($categorias as $c): ?>
                    <a href="<?= montarLinkFiltroProdutos(1, (int) $c['id_categoria'], $statusFiltro, $busca) ?>" class="filtro-pill<?= $id_categoria === (int) $c['id_categoria'] ? ' ativa' : '' ?>"><?= htmlspecialchars($c['nome']) ?></a>
                <?php endforeach; ?>
                <a href="<?= montarLinkFiltroProdutos(1, $id_categoria, 'ativos', $busca) ?>" class="filtro-pill<?= $statusFiltro === 'ativos' ? ' ativa' : '' ?>">Ativos</a>
                <a href="<?= montarLinkFiltroProdutos(1, $id_categoria, 'inativos', $busca) ?>" class="filtro-pill<?= $statusFiltro === 'inativos' ? ' ativa' : '' ?>">Inativos</a>
            </div>

            <form method="get" class="busca-lista">
                <?php if ($id_categoria > 0): ?><input type="hidden" name="categoria" value="<?= $id_categoria ?>"><?php endif; ?>
                <?php if ($statusFiltro !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFiltro) ?>"><?php endif; ?>
                <input type="text" name="busca" placeholder="Buscar produto por nome ou código..." value="<?= htmlspecialchars($busca) ?>">
                <button type="submit">Buscar</button>
            </form>

            <?php if (empty($produtos)): ?>
                <p>Nenhum produto encontrado.</p>
            <?php else: ?>
            <div class="tabela-wrap">
            <table>
                <tr><th></th><th>Código</th><th>Nome</th><th>Categoria</th><th>Preço</th><th>Estoque</th><th>Status</th><th></th><th></th></tr>
                <?php foreach ($produtos as $p): ?>
                <?php
                    $urlProdutoP = 'https://brechodaveve.codernex.com.br/loja/produto.php?id=' . $p['id_produto'];
                    $linkWhatsappP = montarLinkCompartilharWhatsapp($p['nome'], (float) $p['preco_base'], $urlProdutoP);
                ?>
                <tr>
                    <td>
                        <?php if ($p['foto']): ?>
                            <img src="/<?= htmlspecialchars(fotoComVersao($p['foto'])) ?>" alt="" class="foto-produto-mini">
                        <?php else: ?>
                            <img src="/assets/img/produto-indisponivel.svg" alt="" class="foto-produto-mini">
                        <?php endif; ?>
                    </td>
                    <td><?= $p['codigo'] ? '<span class="codigo-produto-mini">' . htmlspecialchars($p['codigo']) . '</span>' : '—' ?></td>
                    <td><?= htmlspecialchars($p['nome']) ?></td>
                    <td><?= htmlspecialchars($p['categoria']) ?></td>
                    <td>R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></td>
                    <td><?= (int) $p['estoque_total'] ?></td>
                    <td><span class="status-pill<?= $p['ativo'] ? ' sucesso' : ' erro' ?>"><?= $p['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td><a href="/produtos/editar.php?id=<?= $p['id_produto'] ?>" class="btn-sm btn-outline"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M4 20h4l10.5-10.5a2 2 0 0 0 0-2.83l-1.17-1.17a2 2 0 0 0-2.83 0L4 16v4Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg> Editar</a></td>
                    <td>
                        <a href="<?= htmlspecialchars($linkWhatsappP) ?>" target="_blank" rel="noopener" class="btn-compartilhar-whatsapp icone-so" aria-label="Compartilhar no WhatsApp" title="Compartilhar no WhatsApp">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
            <nav class="paginacao">
                <?php if ($pagina > 1): ?><a href="<?= montarLinkFiltroProdutos($pagina - 1, $id_categoria, $statusFiltro, $busca) ?>">‹ Anterior</a><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                    <a href="<?= montarLinkFiltroProdutos($p, $id_categoria, $statusFiltro, $busca) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkFiltroProdutos($pagina + 1, $id_categoria, $statusFiltro, $busca) ?>">Próxima ›</a><?php endif; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
