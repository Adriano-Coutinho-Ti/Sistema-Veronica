<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$stmt = $pdo->query(
    "SELECT cs.*, ua.nome AS aberto_por_nome, uf.nome AS fechado_por_nome
     FROM caixa_sessoes cs
     JOIN usuarios ua ON ua.id_usuario = cs.aberto_por
     LEFT JOIN usuarios uf ON uf.id_usuario = cs.fechado_por
     ORDER BY cs.data_abertura DESC"
);
$sessoes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Histórico de caixas</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Histórico de caixas</h1>

    <div class="tabela-wrap">
    <table>
        <tr>
            <th>#</th><th>Abertura</th><th>Aberto por</th><th>Valor inicial</th>
            <th>Status</th><th>Fechamento</th><th>Fechado por</th>
            <th>Valor informado</th><th>Esperado</th><th>Diferença</th><th>Obs.</th>
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
        </tr>
        <?php endforeach; ?>
        <?php if (empty($sessoes)): ?>
        <tr><td colspan="11">Nenhum caixa registrado.</td></tr>
        <?php endif; ?>
    </table>
    </div>
</main>
</body>
</html>
