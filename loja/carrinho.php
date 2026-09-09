<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

liberarReservasExpiradas($pdo);

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

$itens = [];
$total = 0;
$dataVenda = null;
if ($id_venda) {
    $stmt = $pdo->prepare('SELECT id_item, nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
    $stmt->execute([':id' => $id_venda]);
    $itens = $stmt->fetchAll();

    $stmtV = $pdo->prepare('SELECT valor_total, data_venda FROM vendas WHERE id_venda = :id');
    $stmtV->execute([':id' => $id_venda]);
    $venda = $stmtV->fetch();
    $total = (float) $venda['valor_total'];
    $dataVenda = $venda['data_venda'];
}

$config = $pdo->query('SELECT prazo_reserva_minutos FROM config_loja WHERE id_config = 1')->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Carrinho</title></head>
<body>
    <p><a href="/loja/index.php">Continuar comprando</a></p>
    <h1>Carrinho</h1>

    <?php if (empty($itens)): ?>
    <p>Seu carrinho está vazio.</p>
    <?php else: ?>
    <?php if ($dataVenda): ?>
    <p id="contagem"></p>
    <script>
    const expiraEm = new Date('<?= date('c', strtotime($dataVenda) + ((int) $config['prazo_reserva_minutos'] * 60)) ?>').getTime();
    function atualizarContagem() {
        const restante = Math.max(0, Math.floor((expiraEm - Date.now()) / 1000));
        const min = Math.floor(restante / 60);
        const seg = restante % 60;
        document.getElementById('contagem').textContent = 'Tempo pra pagar: ' + min + ':' + String(seg).padStart(2, '0');
        if (restante <= 0) {
            window.location.reload();
        }
    }
    atualizarContagem();
    setInterval(atualizarContagem, 1000);
    </script>
    <?php endif; ?>

    <ul>
        <?php foreach ($itens as $item): ?>
        <li>
            <?= (int) $item['quantidade'] ?>x <?= htmlspecialchars($item['nome_produto']) ?>
            <?= $item['descricao_combinacao'] ? '(' . htmlspecialchars($item['descricao_combinacao']) . ')' : '' ?>
            — R$ <?= number_format($item['subtotal'], 2, ',', '.') ?>
            <button type="button" data-id-item="<?= $item['id_item'] ?>" class="btn-remover">remover</button>
        </li>
        <?php endforeach; ?>
    </ul>
    <p>Total: R$ <?= number_format($total, 2, ',', '.') ?></p>
    <p><a href="/loja/checkout.php">Ir para o checkout</a></p>
    <script>
    document.querySelectorAll('.btn-remover').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fetch('/loja/ajax/remover_item.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_item=' + btn.dataset.idItem
            }).then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { alert(data.message); }
            });
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
