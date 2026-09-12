<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirClienteLogado();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT v.*, fe.tipo AS entrega_tipo
     FROM vendas v
     LEFT JOIN formas_entrega fe ON fe.id_entrega = v.id_entrega
     WHERE v.id_venda = :id AND v.id_cliente = :ic AND v.origem = 'loja'"
);
$stmt->execute([':id' => $id_venda, ':ic' => $id_cliente]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Pedido não encontrado.';
    exit;
}

$itens = $pdo->prepare('SELECT nome_produto, descricao_combinacao, quantidade, subtotal FROM itens_venda WHERE id_venda = :id');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Status do pedido</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Pedido #<?= $id_venda ?></h1>
    <?php if ($venda['status'] === 'Cancelado'): ?>
        <p class="alert alert-erro">Item não liberado. Demora no pagamento.</p>
        <p>Se você já pagou, a loja entrará em contato pra resolver (reembolso ou reposição).</p>
    <?php elseif ($venda['status'] === 'Pago'): ?>
        <p class="alert alert-sucesso"><?= htmlspecialchars(rotuloStatusPedido($venda['status'], $venda['status_entrega'], $venda['entrega_tipo'])) ?></p>
    <?php else: ?>
        <p>Aguardando confirmação do pagamento...</p>
        <script>setTimeout(function () { window.location.reload(); }, 5000);</script>
    <?php endif; ?>

    <?php if (!empty($listaItens)): ?>
    <table border="1" cellpadding="6">
        <tr><th>Item</th><th>Variação</th><th>Qtd</th><th>Subtotal</th></tr>
        <?php foreach ($listaItens as $it): ?>
        <tr>
            <td><?= htmlspecialchars($it['nome_produto']) ?></td>
            <td><?= htmlspecialchars($it['descricao_combinacao'] ?? '—') ?></td>
            <td><?= (int) $it['quantidade'] ?></td>
            <td>R$ <?= number_format($it['subtotal'], 2, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p>Total: R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></p>
    <?php endif; ?>

    <p><a href="/loja/meus_pedidos.php" class="btn-texto">← Meus pedidos</a></p>
</main>
</body>
</html>
