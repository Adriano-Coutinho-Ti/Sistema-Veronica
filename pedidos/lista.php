<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirLogin();

$filtro = $_GET['status_entrega'] ?? '';
$filtroValido = in_array($filtro, STATUS_ENTREGA_VALIDOS, true) ? $filtro : '';

$sql = "SELECT v.id_venda, v.data_venda, v.valor_total, v.status, v.status_entrega, c.nome AS cliente_nome
        FROM vendas v
        LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
        WHERE v.origem = 'loja' AND v.status IN ('Pago', 'Cancelado')";
$params = [];
if ($filtroValido !== '') {
    $sql .= ' AND v.status_entrega = :se';
    $params[':se'] = $filtroValido;
}
$sql .= ' ORDER BY v.data_venda DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pedidos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pedidos</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Pedidos da loja online</h1>

    <form method="get">
        <label>Filtrar por etapa
            <select name="status_entrega" onchange="this.form.submit()">
                <option value="">Todas</option>
                <?php foreach (STATUS_ENTREGA_VALIDOS as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>" <?= $filtroValido === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>

    <table border="1" cellpadding="6">
        <tr><th>Pedido</th><th>Cliente</th><th>Data</th><th>Total</th><th>Status</th><th></th></tr>
        <?php foreach ($pedidos as $p): ?>
        <tr>
            <td>#<?= (int) $p['id_venda'] ?></td>
            <td><?= htmlspecialchars($p['cliente_nome'] ?? '—') ?></td>
            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime($p['data_venda']))) ?></td>
            <td>R$ <?= number_format($p['valor_total'], 2, ',', '.') ?></td>
            <td><?= htmlspecialchars(rotuloStatusPedido($p['status'], $p['status_entrega'])) ?></td>
            <td><a href="/pedidos/detalhe.php?id_venda=<?= (int) $p['id_venda'] ?>">Ver</a></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($pedidos)): ?>
        <tr><td colspan="6">Nenhum pedido encontrado.</td></tr>
        <?php endif; ?>
    </table>
</main>
</body>
</html>
