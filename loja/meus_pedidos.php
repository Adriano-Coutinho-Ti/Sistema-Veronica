<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT v.id_venda, v.data_venda, v.valor_total, v.status, v.status_entrega, fe.tipo AS entrega_tipo,
            (SELECT pf.caminho_arquivo
             FROM itens_venda iv
             JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
             JOIN produto_fotos pf ON pf.id_produto = pv.id_produto
             WHERE iv.id_venda = v.id_venda
             ORDER BY iv.id_item, pf.ordem LIMIT 1) AS foto
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
        <p>Você ainda não fez nenhum pedido. <a href="/loja/index.php">Ver catálogo</a></p>
    <?php endif; ?>

    <?php foreach ($pedidos as $p): ?>
        <?php
            $rotulo = rotuloStatusPedido($p['status'], $p['status_entrega'], $p['entrega_tipo']);
            $classePill = $p['status'] === 'Cancelado' ? 'cancelado' : ($p['status_entrega'] === 'Entregue' ? 'concluido' : '');
        ?>
        <a href="/loja/pedido_status.php?id_venda=<?= (int) $p['id_venda'] ?>" class="pedido-card">
            <div class="foto">
                <?php if ($p['foto']): ?>
                    <img src="/<?= htmlspecialchars($p['foto']) ?>" alt="Pedido #<?= (int) $p['id_venda'] ?>">
                <?php endif; ?>
            </div>
            <div>
                <div class="numero">Pedido #<?= (int) $p['id_venda'] ?></div>
                <div class="data"><?= htmlspecialchars(date('d/m/Y', strtotime($p['data_venda']))) ?></div>
                <div class="total">R$ <?= number_format($p['valor_total'], 2, ',', '.') ?></div>
                <span class="status-pill<?= $classePill ? ' ' . $classePill : '' ?>"><?= htmlspecialchars($rotulo) ?></span>
            </div>
        </a>
    <?php endforeach; ?>
</main>
</body>
</html>
