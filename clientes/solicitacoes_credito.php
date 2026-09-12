<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_solicitacao = (int) ($_POST['id_solicitacao'] ?? 0);
    $acao = $_POST['acao'] ?? '';

    $stmt = $pdo->prepare("SELECT id_cliente, valor_solicitado FROM solicitacoes_credito WHERE id_solicitacao = :id AND status = 'Pendente'");
    $stmt->execute([':id' => $id_solicitacao]);
    $solicitacao = $stmt->fetch();

    if (!$solicitacao) {
        $erro = 'Solicitação não encontrada ou já respondida.';
    } elseif ($acao === 'aprovar') {
        $valorAprovado = (float) str_replace(',', '.', $_POST['valor_aprovado'] ?? '0');
        if ($valorAprovado <= 0) {
            $erro = 'Informe um valor de limite válido.';
        } else {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE clientes SET limite_credito = :limite WHERE id_cliente = :ic')
                ->execute([':limite' => $valorAprovado, ':ic' => $solicitacao['id_cliente']]);
            $pdo->prepare(
                "UPDATE solicitacoes_credito SET status = 'Aprovada', valor_aprovado = :va, respondido_em = NOW(), respondido_por = :ru WHERE id_solicitacao = :id"
            )->execute([':va' => $valorAprovado, ':ru' => $_SESSION['id_usuario'], ':id' => $id_solicitacao]);
            $pdo->commit();
            header('Location: /clientes/solicitacoes_credito.php?respondido=1');
            exit;
        }
    } elseif ($acao === 'rejeitar') {
        $observacao = trim($_POST['observacao_admin'] ?? '') ?: null;
        $pdo->prepare(
            "UPDATE solicitacoes_credito SET status = 'Rejeitada', observacao_admin = :obs, respondido_em = NOW(), respondido_por = :ru WHERE id_solicitacao = :id"
        )->execute([':obs' => $observacao, ':ru' => $_SESSION['id_usuario'], ':id' => $id_solicitacao]);
        header('Location: /clientes/solicitacoes_credito.php?respondido=1');
        exit;
    } else {
        $erro = 'Ação inválida.';
    }
}

$pendentes = $pdo->query(
    "SELECT sc.id_solicitacao, sc.valor_solicitado, sc.criado_em, c.nome, c.whatsapp, c.limite_credito
     FROM solicitacoes_credito sc
     JOIN clientes c ON c.id_cliente = sc.id_cliente
     WHERE sc.status = 'Pendente'
     ORDER BY sc.criado_em ASC"
)->fetchAll();

$respondidas = $pdo->query(
    "SELECT sc.id_solicitacao, sc.valor_solicitado, sc.status, sc.valor_aprovado, sc.respondido_em, c.nome
     FROM solicitacoes_credito sc
     JOIN clientes c ON c.id_cliente = sc.id_cliente
     WHERE sc.status != 'Pendente'
     ORDER BY sc.respondido_em DESC
     LIMIT 20"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Solicitações de crédito</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Solicitações de crédito</h1>
    <?php if (isset($_GET['respondido'])): ?><p class="alert alert-sucesso">Solicitação respondida.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <h3>Pendentes</h3>
    <?php if (empty($pendentes)): ?>
        <p>Nenhuma solicitação pendente.</p>
    <?php else: ?>
    <table border="1" cellpadding="6">
        <tr><th>Cliente</th><th>WhatsApp</th><th>Limite atual</th><th>Valor pedido</th><th>Data</th><th>Responder</th></tr>
        <?php foreach ($pendentes as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['nome']) ?></td>
            <td><?= htmlspecialchars($s['whatsapp']) ?></td>
            <td>R$ <?= number_format($s['limite_credito'], 2, ',', '.') ?></td>
            <td>R$ <?= number_format($s['valor_solicitado'], 2, ',', '.') ?></td>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($s['criado_em']))) ?></td>
            <td>
                <form method="post" style="display:inline-flex; gap:6px; align-items:center;">
                    <input type="hidden" name="id_solicitacao" value="<?= $s['id_solicitacao'] ?>">
                    <input type="hidden" name="acao" value="aprovar">
                    <input type="text" name="valor_aprovado" value="<?= number_format($s['valor_solicitado'], 2, ',', '') ?>" style="width:100px;">
                    <button type="submit">Aprovar</button>
                </form>
                <form method="post" style="display:inline; margin-left:6px;" onsubmit="return confirm('Rejeitar esta solicitação?');">
                    <input type="hidden" name="id_solicitacao" value="<?= $s['id_solicitacao'] ?>">
                    <input type="hidden" name="acao" value="rejeitar">
                    <button type="submit">Rejeitar</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>

    <h3>Últimas respondidas</h3>
    <?php if (empty($respondidas)): ?>
        <p>Nenhuma ainda.</p>
    <?php else: ?>
    <table border="1" cellpadding="6">
        <tr><th>Cliente</th><th>Pedido</th><th>Status</th><th>Aprovado</th><th>Data</th></tr>
        <?php foreach ($respondidas as $s): ?>
        <tr>
            <td><?= htmlspecialchars($s['nome']) ?></td>
            <td>R$ <?= number_format($s['valor_solicitado'], 2, ',', '.') ?></td>
            <td><?= htmlspecialchars($s['status']) ?></td>
            <td><?= $s['valor_aprovado'] !== null ? 'R$ ' . number_format($s['valor_aprovado'], 2, ',', '.') : '—' ?></td>
            <td><?= htmlspecialchars(date('d/m/Y', strtotime($s['respondido_em']))) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</main>
</body>
</html>
