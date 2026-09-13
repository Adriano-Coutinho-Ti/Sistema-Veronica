<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirLogin();

$id_venda = (int) ($_GET['id_venda'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT v.*, c.nome AS cliente_nome, c.whatsapp AS cliente_whatsapp, c.endereco AS cliente_endereco,
            fe.nome AS entrega_nome, fe.tipo AS entrega_tipo
     FROM vendas v
     LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
     LEFT JOIN formas_entrega fe ON fe.id_entrega = v.id_entrega
     WHERE v.id_venda = :id AND v.origem = 'loja'"
);
$stmt->execute([':id' => $id_venda]);
$pedido = $stmt->fetch();

if (!$pedido) {
    http_response_code(404);
    echo 'Pedido não encontrado.';
    exit;
}

$itens = $pdo->prepare('SELECT nome_produto, descricao_combinacao, quantidade, preco_unit, subtotal FROM itens_venda WHERE id_venda = :id');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pedido #<?= $id_venda ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Pedido #<?= $id_venda ?></h1>
    <p><span class="status-pill"><?= htmlspecialchars(rotuloStatusPedido($pedido['status'], $pedido['status_entrega'], $pedido['entrega_tipo'])) ?></span></p>

    <div class="grade-2col">
    <div class="card">
    <h3>Cliente</h3>
    <p>
        <?= htmlspecialchars($pedido['cliente_nome'] ?? '—') ?><br>
        WhatsApp: <?= htmlspecialchars($pedido['cliente_whatsapp'] ?? '—') ?><br>
        <?php if ($pedido['entrega_tipo'] === 'entrega'): ?>
            Endereço: <?= htmlspecialchars($pedido['cliente_endereco'] ?? '—') ?><br>
        <?php endif; ?>
        Entrega: <?= htmlspecialchars($pedido['entrega_nome'] ?? '—') ?>
    </p>
    </div>

    <div class="card">
    <p style="font-weight:700; font-size:1.1rem;">Total: R$ <?= number_format($pedido['valor_total'], 2, ',', '.') ?></p>
    <p>Pagamento: <?= htmlspecialchars($pedido['forma_pagamento'] ?? '—') ?></p>
    </div>
    </div>

    <h3>Itens</h3>
    <div class="tabela-wrap">
    <table>
        <tr><th>Produto</th><th>Variação</th><th>Qtd</th><th>Preço</th><th>Subtotal</th></tr>
        <?php foreach ($listaItens as $it): ?>
        <tr>
            <td><?= htmlspecialchars($it['nome_produto']) ?></td>
            <td><?= htmlspecialchars($it['descricao_combinacao'] ?? '—') ?></td>
            <td><?= (int) $it['quantidade'] ?></td>
            <td>R$ <?= number_format($it['preco_unit'], 2, ',', '.') ?></td>
            <td>R$ <?= number_format($it['subtotal'], 2, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>

    <?php if ($pedido['status'] === 'Pago'): ?>
    <div class="card">
    <h3>Avançar etapa do pedido</h3>
    <form id="form-status" class="form-linha-compacta">
        <select id="select-status">
            <?php foreach (STATUS_ENTREGA_VALIDOS as $s): ?>
                <option value="<?= htmlspecialchars($s) ?>" <?= $pedido['status_entrega'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Salvar status</button>
    </form>
    <p id="status-msg"></p>
    </div>

        <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
        <div class="card">
        <h3>Cancelar pedido</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Devolve o estoque dos itens e, se parte do pagamento foi em Linha de Crédito, estorna o saldo devedor do cliente. Pagamento em dinheiro/cartão/Pix precisa ser reembolsado por fora.</p>
        <button id="btn-cancelar" class="btn-perigo">Cancelar este pedido</button>
        <p id="cancelar-msg"></p>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<script>
const idVenda = <?= $id_venda ?>;

const formStatus = document.getElementById('form-status');
if (formStatus) {
    formStatus.addEventListener('submit', function (e) {
        e.preventDefault();
        const status_entrega = document.getElementById('select-status').value;
        fetch('/pedidos/ajax/atualizar_status.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_venda=' + idVenda + '&status_entrega=' + encodeURIComponent(status_entrega)
        }).then(r => r.json()).then(data => {
            document.getElementById('status-msg').textContent = data.message;
            if (data.success) { window.location.reload(); }
        });
    });
}

const btnCancelar = document.getElementById('btn-cancelar');
if (btnCancelar) {
    btnCancelar.addEventListener('click', function () {
        confirmarAcao('Cancelar este pedido? Estoque e crédito (se houver) serão estornados.').then(function (ok) {
            if (!ok) { return; }
            fetch('/pedidos/ajax/cancelar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_venda=' + idVenda
            }).then(r => r.json()).then(data => {
                document.getElementById('cancelar-msg').textContent = data.message;
                if (data.success) { window.location.reload(); }
            });
        });
    });
}
</script>
</main>
</body>
</html>
