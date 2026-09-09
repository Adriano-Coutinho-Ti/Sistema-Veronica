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
$segundosRestantes = 0;
if ($id_venda) {
    $stmt = $pdo->prepare('SELECT id_item, nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
    $stmt->execute([':id' => $id_venda]);
    $itens = $stmt->fetchAll();

    // Os segundos restantes são calculados no próprio MySQL, não em PHP: se o timezone do PHP
    // e o do MySQL divergirem (comum num WAMP padrão), comparar data_venda do banco com a hora
    // do PHP/navegador dá uma contagem errada — podendo zerar na hora e deixar a página num
    // laço infinito de reload.
    $stmtV = $pdo->prepare(
        "SELECT v.valor_total, v.data_venda,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(v.data_venda, INTERVAL cl.prazo_reserva_minutos MINUTE))) AS segundos_restantes
         FROM vendas v
         JOIN config_loja cl ON cl.id_config = 1
         WHERE v.id_venda = :id"
    );
    $stmtV->execute([':id' => $id_venda]);
    $venda = $stmtV->fetch();
    $total = (float) $venda['valor_total'];
    $dataVenda = $venda['data_venda'];
    $segundosRestantes = (int) $venda['segundos_restantes'];
}
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
    let restante = <?= $segundosRestantes ?>;
    function atualizarContagem() {
        const min = Math.floor(restante / 60);
        const seg = restante % 60;
        document.getElementById('contagem').textContent = 'Tempo pra pagar: ' + min + ':' + String(seg).padStart(2, '0');
        if (restante <= 0) {
            window.location.reload();
            return;
        }
        restante--;
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
            }).catch(err => {
                alert('Erro de conexão. Tente novamente.');
            });
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
