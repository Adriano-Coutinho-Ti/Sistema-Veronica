<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT v.id_venda, v.data_venda, v.valor_total, v.status, v.status_entrega, fe.tipo AS entrega_tipo
     FROM vendas v
     LEFT JOIN formas_entrega fe ON fe.id_entrega = v.id_entrega
     WHERE v.id_cliente = :ic AND v.origem = 'loja' AND v.status IN ('Pago', 'Cancelado')
     ORDER BY v.data_venda DESC"
);
$stmt->execute([':ic' => $id_cliente]);
$pedidos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Meus pedidos</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Meus pedidos</h1>

    <?php if (empty($pedidos)): ?>
        <p>Você ainda não fez nenhum pedido.</p>
    <?php endif; ?>

    <?php foreach ($pedidos as $p): ?>
        <div class="product-card" style="margin-bottom:12px; display:block;">
            <strong>Pedido #<?= (int) $p['id_venda'] ?></strong> — <?= htmlspecialchars(date('d/m/Y', strtotime($p['data_venda']))) ?><br>
            R$ <?= number_format($p['valor_total'], 2, ',', '.') ?> —
            <?= htmlspecialchars(rotuloStatusPedido($p['status'], $p['status_entrega'], $p['entrega_tipo'])) ?><br>
            <a href="/loja/pedido_status.php?id_venda=<?= (int) $p['id_venda'] ?>">Ver detalhes</a>
        </div>
    <?php endforeach; ?>
</main>
</body>
</html>
