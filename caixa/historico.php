<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

const CAIXAS_POR_PAGINA = 20;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$totalCaixas = (int) $pdo->query('SELECT COUNT(*) FROM caixa_sessoes')->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalCaixas / CAIXAS_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * CAIXAS_POR_PAGINA;

$stmt = $pdo->prepare(
    "SELECT cs.*, ua.nome AS aberto_por_nome, uf.nome AS fechado_por_nome
     FROM caixa_sessoes cs
     JOIN usuarios ua ON ua.id_usuario = cs.aberto_por
     LEFT JOIN usuarios uf ON uf.id_usuario = cs.fechado_por
     ORDER BY cs.data_abertura DESC
     LIMIT :limite OFFSET :offset"
);
$stmt->bindValue(':limite', CAIXAS_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$sessoes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Histórico de caixas</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Histórico de caixas</h1>
            <span class="subtitulo"><?= $totalCaixas ?> sessão<?= $totalCaixas === 1 ? '' : 'ões' ?> registrada<?= $totalCaixas === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/caixa/index.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar ao PDV
        </a>
        <a href="/caixa/vendas.php" class="btn-outline btn-sm">Vendas do caixa</a>
    </p>

    <div class="card">
        <?php if (empty($sessoes)): ?>
        <p class="alert alert-info">Nenhum caixa registrado ainda.</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr>
                <th>#</th><th>Abertura</th><th>Aberto por</th><th>Valor inicial</th>
                <th>Status</th><th>Fechamento</th><th>Fechado por</th>
                <th>Valor informado</th><th>Esperado</th><th>Diferença</th><th>Obs.</th><th></th>
            </tr>
            <?php foreach ($sessoes as $s): ?>
            <tr>
                <td>#<?= (int) $s['id_caixa'] ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($s['data_abertura']))) ?></td>
                <td><?= htmlspecialchars($s['aberto_por_nome']) ?></td>
                <td>R$ <?= number_format($s['valor_inicial'], 2, ',', '.') ?></td>
                <td><span class="status-pill<?= $s['status'] === 'aberto' ? '' : ' sucesso' ?>"><?= $s['status'] === 'aberto' ? 'Aberto' : 'Fechado' ?></span></td>
                <td><?= $s['data_fechamento'] ? htmlspecialchars(date('d/m/Y H:i', strtotime($s['data_fechamento']))) : '—' ?></td>
                <td><?= htmlspecialchars($s['fechado_por_nome'] ?? '—') ?></td>
                <td><?= $s['valor_final_informado'] !== null ? 'R$ ' . number_format($s['valor_final_informado'], 2, ',', '.') : '—' ?></td>
                <td><?= $s['valor_esperado'] !== null ? 'R$ ' . number_format($s['valor_esperado'], 2, ',', '.') : '—' ?></td>
                <td<?= $s['diferenca'] !== null && (float) $s['diferenca'] !== 0.0 ? ' style="color:var(--cor-erro); font-weight:700;"' : '' ?>>
                    <?= $s['diferenca'] !== null ? 'R$ ' . number_format($s['diferenca'], 2, ',', '.') : '—' ?>
                </td>
                <td><?= htmlspecialchars($s['observacao_fechamento'] ?? '—') ?></td>
                <td><a href="/caixa/historico_detalhe.php?id_caixa=<?= (int) $s['id_caixa'] ?>" class="btn-sm btn-outline">Ver vendas</a></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>

        <?php if ($totalPaginas > 1): ?>
        <nav class="paginacao">
            <?php if ($pagina > 1): ?><a href="?pagina=<?= $pagina - 1 ?>">‹ Anterior</a><?php endif; ?>
            <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                <a href="?pagina=<?= $p ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($pagina < $totalPaginas): ?><a href="?pagina=<?= $pagina + 1 ?>">Próxima ›</a><?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
