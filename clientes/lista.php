<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
exigirLogin();

$busca = trim($_GET['busca'] ?? '');
$statusFiltro = $_GET['status'] ?? '';
if (!in_array($statusFiltro, ['verificados', 'nao_verificados', 'sem_email'], true)) {
    $statusFiltro = '';
}

$where = 'excluido_em IS NULL';
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
$whatsappAtivo = (bool) $pdo->query('SELECT whatsapp_verificacao_ativo FROM config_dev WHERE id_config = 1')->fetchColumn();
$totalNaLixeira = (int) $pdo->query('SELECT COUNT(*) FROM clientes WHERE excluido_em IS NOT NULL')->fetchColumn();
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE $where");
$stmtTotal->execute($params);
$totalClientes = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalClientes / CLIENTES_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * CLIENTES_POR_PAGINA;

$stmt = $pdo->prepare(
    "SELECT id_cliente, nome, whatsapp, email, email_verificado_em, whatsapp_verificado_em FROM clientes WHERE $where
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
    <?php if (isset($_GET['lixeira'])): ?><p class="alert alert-sucesso">Cliente movido para a lixeira.</p><?php endif; ?>

    <p class="acoes-topo">
        <a href="/clientes/novo.php" class="btn">+ Novo cliente</a>
        <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
        <a href="/clientes/lixeira.php" class="btn-outline">Lixeira<?= $totalNaLixeira > 0 ? ' (' . $totalNaLixeira . ')' : '' ?></a>
        <?php endif; ?>
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
            <div class="alternador-visualizacao" data-chave="clientes" data-alvo-lista="visualizacao-lista" data-alvo-cards="visualizacao-cards">
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
                <tr><th>Nome</th><th>WhatsApp</th><th>WhatsApp validado</th><th>E-mail</th><th>E-mail validado</th><th></th></tr>
                <?php foreach ($clientes as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['nome']) ?></td>
                    <td><?= htmlspecialchars(formatarWhatsappExibicao($c['whatsapp'])) ?></td>
                    <td><?= seloContatoValidado(true, $c['whatsapp_verificado_em'], $whatsappAtivo) ?></td>
                    <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
                    <td><?= seloContatoValidado(!empty($c['email']), $c['email_verificado_em']) ?></td>
                    <td><a href="/clientes/detalhe.php?id=<?= $c['id_cliente'] ?>" class="btn-sm btn-outline">ver</a></td>
                </tr>
                <?php endforeach; ?>
            </table>
            </div>
            </div>

            <div id="visualizacao-cards" hidden>
            <div class="grade-cards">
                <?php foreach ($clientes as $c): ?>
                <div class="item-card">
                    <div class="item-card-topo">
                        <strong><?= htmlspecialchars($c['nome']) ?></strong>
                    </div>
                    <p><?= htmlspecialchars(formatarWhatsappExibicao($c['whatsapp'])) ?> <?= seloContatoValidado(true, $c['whatsapp_verificado_em'], $whatsappAtivo) ?></p>
                    <?php if ($c['email']): ?><p><?= htmlspecialchars($c['email']) ?> <?= seloContatoValidado(true, $c['email_verificado_em']) ?></p><?php endif; ?>
                    <div class="celula-acoes">
                        <a href="/clientes/detalhe.php?id=<?= $c['id_cliente'] ?>" class="btn-sm btn-outline">ver</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
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
