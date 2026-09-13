<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$busca = trim($_GET['busca'] ?? '');
$statusFiltro = $_GET['status'] ?? '';
if (!in_array($statusFiltro, ['verificados', 'nao_verificados', 'sem_email'], true)) {
    $statusFiltro = '';
}

$where = '1=1';
$params = [];
if ($busca !== '') {
    $where .= ' AND (nome LIKE :busca OR whatsapp LIKE :busca OR email LIKE :busca)';
    $params[':busca'] = '%' . $busca . '%';
}
if ($statusFiltro === 'verificados') {
    $where .= ' AND email_verificado_em IS NOT NULL';
} elseif ($statusFiltro === 'nao_verificados') {
    $where .= " AND email IS NOT NULL AND email <> '' AND email_verificado_em IS NULL";
} elseif ($statusFiltro === 'sem_email') {
    $where .= " AND (email IS NULL OR email = '')";
}

const CLIENTES_POR_PAGINA = 20;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE $where");
$stmtTotal->execute($params);
$totalClientes = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalClientes / CLIENTES_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * CLIENTES_POR_PAGINA;

$stmt = $pdo->prepare(
    "SELECT id_cliente, nome, whatsapp, email, email_verificado_em FROM clientes WHERE $where
     ORDER BY nome LIMIT :limite OFFSET :offset"
);
foreach ($params as $chave => $valor) {
    $stmt->bindValue($chave, $valor);
}
$stmt->bindValue(':limite', CLIENTES_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$clientes = $stmt->fetchAll();

function montarLinkFiltroClientes(int $pagina, string $status, string $busca): string
{
    $params = ['pagina' => $pagina];
    if ($status !== '') {
        $params['status'] = $status;
    }
    if ($busca !== '') {
        $params['busca'] = $busca;
    }
    return '/clientes/lista.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clientes</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8c0-3.31 3.13-6 7-6s7 2.69 7 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Clientes</h1>
            <span class="subtitulo"><?= $totalClientes ?> cliente<?= $totalClientes === 1 ? '' : 's' ?> cadastrado<?= $totalClientes === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Cliente cadastrado com sucesso.</p><?php endif; ?>

    <p class="acoes-topo">
        <a href="/clientes/novo.php" class="btn">+ Novo cliente</a>
    </p>

    <div class="layout-lateral">
        <aside class="filtros-lateral">
            <h2>E-mail</h2>
            <nav>
                <a href="<?= montarLinkFiltroClientes(1, '', $busca) ?>" class="<?= $statusFiltro === '' ? 'ativa' : '' ?>">Todos</a>
                <a href="<?= montarLinkFiltroClientes(1, 'verificados', $busca) ?>" class="<?= $statusFiltro === 'verificados' ? 'ativa' : '' ?>">Verificados</a>
                <a href="<?= montarLinkFiltroClientes(1, 'nao_verificados', $busca) ?>" class="<?= $statusFiltro === 'nao_verificados' ? 'ativa' : '' ?>">Não verificados</a>
                <a href="<?= montarLinkFiltroClientes(1, 'sem_email', $busca) ?>" class="<?= $statusFiltro === 'sem_email' ? 'ativa' : '' ?>">Sem e-mail</a>
            </nav>
        </aside>

        <div>
            <div class="filtros-mobile">
                <a href="<?= montarLinkFiltroClientes(1, '', $busca) ?>" class="filtro-pill<?= $statusFiltro === '' ? ' ativa' : '' ?>">Todos</a>
                <a href="<?= montarLinkFiltroClientes(1, 'verificados', $busca) ?>" class="filtro-pill<?= $statusFiltro === 'verificados' ? ' ativa' : '' ?>">Verificados</a>
                <a href="<?= montarLinkFiltroClientes(1, 'nao_verificados', $busca) ?>" class="filtro-pill<?= $statusFiltro === 'nao_verificados' ? ' ativa' : '' ?>">Não verificados</a>
                <a href="<?= montarLinkFiltroClientes(1, 'sem_email', $busca) ?>" class="filtro-pill<?= $statusFiltro === 'sem_email' ? ' ativa' : '' ?>">Sem e-mail</a>
            </div>

            <form method="get" class="busca-lista">
                <?php if ($statusFiltro !== ''): ?><input type="hidden" name="status" value="<?= htmlspecialchars($statusFiltro) ?>"><?php endif; ?>
                <input type="text" name="busca" placeholder="Buscar por nome, WhatsApp ou e-mail..." value="<?= htmlspecialchars($busca) ?>">
                <button type="submit">Buscar</button>
            </form>

            <?php if (empty($clientes)): ?>
                <p class="alert alert-info">Nenhum cliente encontrado.</p>
            <?php else: ?>
            <div class="tabela-wrap">
            <table>
                <tr><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th>E-mail verificado</th><th></th></tr>
                <?php foreach ($clientes as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['nome']) ?></td>
                    <td><?= htmlspecialchars($c['whatsapp']) ?></td>
                    <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                    <td>
                        <?php if (!$c['email']): ?>—
                        <?php elseif ($c['email_verificado_em']): ?><span class="status-pill sucesso">✓ Verificado</span>
                        <?php else: ?><span class="status-pill alerta">Não verificado</span>
                        <?php endif; ?>
                    </td>
                    <td><a href="/clientes/detalhe.php?id=<?= $c['id_cliente'] ?>" class="btn-sm btn-outline">ver</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>

            <?php if ($totalPaginas > 1): ?>
            <nav class="paginacao">
                <?php if ($pagina > 1): ?><a href="<?= montarLinkFiltroClientes($pagina - 1, $statusFiltro, $busca) ?>">‹ Anterior</a><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                    <a href="<?= montarLinkFiltroClientes($p, $statusFiltro, $busca) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkFiltroClientes($pagina + 1, $statusFiltro, $busca) ?>">Próxima ›</a><?php endif; ?>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
