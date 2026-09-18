<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clientes_lixeira.php';
exigirAdmin();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_cliente = (int) ($_POST['id_cliente'] ?? 0);
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'restaurar') {
        $sucesso = restaurarClienteDaLixeira($pdo, $id_cliente) ? 'Cliente restaurado — já pode entrar na loja de novo.' : '';
        $erro = $sucesso === '' ? 'Cliente não encontrado na lixeira.' : '';
    } elseif ($acao === 'excluir_definitivo') {
        try {
            $sucesso = excluirClientePermanentemente($pdo, $id_cliente) ? 'Cliente e todos os registros dele foram excluídos definitivamente.' : '';
            $erro = $sucesso === '' ? 'Cliente não encontrado na lixeira.' : '';
        } catch (Throwable $e) {
            error_log('clientes/lixeira.php: falha ao excluir definitivamente: ' . $e->getMessage());
            $erro = 'Não foi possível excluir — nada foi apagado. Tente de novo ou verifique o log do servidor.';
        }
    }
}

$clientes = $pdo->query(
    "SELECT c.id_cliente, c.nome, c.whatsapp, c.email, c.saldo_devedor, c.excluido_em, u.nome AS excluido_por_nome,
            (SELECT COUNT(*) FROM vendas v WHERE v.id_cliente = c.id_cliente) AS qtd_vendas
     FROM clientes c LEFT JOIN usuarios u ON u.id_usuario = c.excluido_por
     WHERE c.excluido_em IS NOT NULL ORDER BY c.excluido_em DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Lixeira de clientes</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <div>
            <h1>Lixeira de clientes</h1>
            <span class="subtitulo"><?= count($clientes) ?> cliente<?= count($clientes) === 1 ? '' : 's' ?> na lixeira</span>
        </div>
    </div>

    <p class="acoes-topo"><a href="/clientes/lista.php" class="btn-outline btn-sm">Voltar aos clientes</a></p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:16px;">Clientes aqui não conseguem entrar na loja e não aparecem nas listas nem no PDV. "Restaurar" devolve tudo como estava. "Excluir definitivamente" apaga o cliente <strong>e todo o histórico dele</strong> (compras, pagamentos, movimentos de crédito, favoritos e solicitações) do banco — não tem como desfazer.</p>

    <div class="card">
    <?php if (empty($clientes)): ?>
        <p class="alert alert-info">A lixeira está vazia.</p>
    <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th>Compras</th><th>Saldo devedor</th><th>Na lixeira desde</th><th></th></tr>
            <?php foreach ($clientes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['nome']) ?></td>
                <td><?= htmlspecialchars($c['whatsapp']) ?></td>
                <td><?= htmlspecialchars($c['email'] ?? '—') ?></td>
                <td><?= (int) $c['qtd_vendas'] ?></td>
                <td>R$ <?= number_format((float) $c['saldo_devedor'], 2, ',', '.') ?></td>
                <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($c['excluido_em']))) ?><?= $c['excluido_por_nome'] ? '<br><small>por ' . htmlspecialchars($c['excluido_por_nome']) . '</small>' : '' ?></td>
                <td class="celula-acoes">
                    <form method="post">
                        <input type="hidden" name="id_cliente" value="<?= $c['id_cliente'] ?>">
                        <input type="hidden" name="acao" value="restaurar">
                        <button type="submit" class="btn-sm btn-outline">Restaurar</button>
                    </form>
                    <form method="post" data-confirm="Excluir DEFINITIVAMENTE <?= htmlspecialchars($c['nome'], ENT_QUOTES) ?>? Todo o histórico dele (<?= (int) $c['qtd_vendas'] ?> compra<?= (int) $c['qtd_vendas'] === 1 ? '' : 's' ?>, pagamentos, movimentos de crédito) será apagado do banco e não dá pra desfazer.">
                        <input type="hidden" name="id_cliente" value="<?= $c['id_cliente'] ?>">
                        <input type="hidden" name="acao" value="excluir_definitivo">
                        <button type="submit" class="btn-sm btn-perigo">Excluir definitivamente</button>
                    </form>
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
