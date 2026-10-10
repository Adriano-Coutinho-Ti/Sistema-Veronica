<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal.php';
exigirLogin();

if (!fiscalAtivo($pdo)) {
    header('Location: /dashboard.php');
    exit;
}

// Sem cron: confere aqui as notas que ficaram "processando".
fiscalConferirPendentes($pdo);

$status = $_GET['status'] ?? '';
$tipo = $_GET['tipo'] ?? '';
$where = '1=1';
$params = [];
if (in_array($status, ['processando', 'autorizada', 'rejeitada', 'cancelada'], true)) {
    $where .= ' AND n.status = :status';
    $params[':status'] = $status;
}
if (in_array($tipo, ['nfce', 'nfe'], true)) {
    $where .= ' AND n.tipo = :tipo';
    $params[':tipo'] = $tipo;
}

$stmt = $pdo->prepare(
    "SELECT n.id_nota, n.id_venda, n.tipo, n.ambiente, n.status, n.valor, n.numero, n.serie, n.erro, n.destinatario_nome, n.criado_em, n.token_publico,
            v.origem
     FROM notas_fiscais n
     JOIN vendas v ON v.id_venda = n.id_venda
     WHERE $where ORDER BY n.id_nota DESC LIMIT 200"
);
$stmt->execute($params);
$notas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Notas fiscais</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <div>
            <h1>Notas fiscais</h1>
            <span class="subtitulo"><?= count($notas) ?> nota<?= count($notas) === 1 ? '' : 's' ?><?= count($notas) === 200 ? ' (mostrando as 200 mais recentes)' : '' ?></span>
        </div>
    </div>

    <div class="card">
        <form method="get" class="form-linha-compacta" style="margin-bottom:18px;">
            <select name="tipo">
                <option value="">NFC-e e NF-e</option>
                <option value="nfce" <?= $tipo === 'nfce' ? 'selected' : '' ?>>NFC-e (cupom do PDV)</option>
                <option value="nfe" <?= $tipo === 'nfe' ? 'selected' : '' ?>>NF-e (A4, vendas online)</option>
            </select>
            <select name="status">
                <option value="">Todas as situações</option>
                <option value="autorizada" <?= $status === 'autorizada' ? 'selected' : '' ?>>Autorizadas</option>
                <option value="processando" <?= $status === 'processando' ? 'selected' : '' ?>>Processando</option>
                <option value="rejeitada" <?= $status === 'rejeitada' ? 'selected' : '' ?>>Recusadas</option>
                <option value="cancelada" <?= $status === 'cancelada' ? 'selected' : '' ?>>Canceladas</option>
            </select>
            <button type="submit">Filtrar</button>
        </form>

        <?php if (empty($notas)): ?>
        <p class="alert alert-info">Nenhuma nota encontrada. As notas são emitidas dentro de cada venda (PDV → Vendas do caixa, ou Pedidos).</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Nota</th><th>Venda</th><th>Data</th><th>Destinatário</th><th>Valor</th><th>Situação</th><th></th></tr>
            <?php foreach ($notas as $n): [$rotulo, $classe] = fiscalSelo($n); ?>
            <tr>
                <td><strong><?= $n['tipo'] === 'nfe' ? 'NF-e' : 'NFC-e' ?></strong><?= $n['numero'] ? ' nº ' . htmlspecialchars((string) $n['numero']) : '' ?><?= $n['ambiente'] === 'homologacao' ? ' <small>(teste)</small>' : '' ?></td>
                <td>#<?= (int) $n['id_venda'] ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($n['criado_em']))) ?></td>
                <td><?= htmlspecialchars($n['destinatario_nome'] ?? '—') ?></td>
                <td>R$ <?= number_format((float) $n['valor'], 2, ',', '.') ?></td>
                <td><span class="status-pill<?= $classe ? ' ' . $classe : '' ?>"><?= htmlspecialchars($rotulo) ?></span><?php if ($n['erro']): ?><div style="font-size:0.85rem; color:var(--cor-texto-suave);"><?= htmlspecialchars((string) $n['erro']) ?></div><?php endif; ?></td>
                <td class="celula-acoes">
                    <?php if ($n['status'] === 'autorizada'): ?>
                    <a href="/notas_fiscais/arquivo.php?id=<?= (int) $n['id_nota'] ?>" target="_blank" rel="noopener" class="btn-sm btn-outline">Abrir</a>
                    <a href="/notas_fiscais/arquivo.php?id=<?= (int) $n['id_nota'] ?>&tipo=xml" class="btn-sm btn-outline">XML</a>
                    <?php endif; ?>
                    <a href="/notas_fiscais/emitir.php?id_venda=<?= (int) $n['id_venda'] ?>" class="btn-sm btn-outline">Ver venda</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
