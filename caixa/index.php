<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

$caixa = exigirCaixaAberto($pdo);

$id_venda = buscarVendaReservadaDoOperador($pdo, (int) $caixa['id_caixa'], (int) $_SESSION['id_usuario']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>PDV</title></head>
<body>
    <h1>PDV — Caixa aberto</h1>
    <p><a href="/caixa/fechamento.php">Fechar caixa</a></p>

    <?php if (!$id_venda): ?>
    <button id="btn-iniciar">Nova venda</button>
    <script>
    document.getElementById('btn-iniciar').addEventListener('click', function () {
        fetch('/caixa/ajax/iniciar_venda.php', { method: 'POST' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            });
    });
    </script>
    <?php else: ?>
    <h2>Venda #<?= $id_venda ?></h2>

    <input type="text" id="termo-busca" placeholder="Buscar produto por nome...">
    <div id="resultados-busca"></div>

    <h3>Carrinho</h3>
    <div id="carrinho"></div>
    <p>Total: R$ <span id="total-venda">0,00</span></p>

    <div id="cliente-vinculado"></div>
    <input type="text" id="termo-cliente" placeholder="Buscar cliente (opcional)...">
    <div id="resultados-cliente"></div>

    <p><a href="/caixa/pagamento.php?id_venda=<?= $id_venda ?>">Ir para pagamento</a></p>

<script>
const idVenda = <?= $id_venda ?>;

document.getElementById('termo-busca').addEventListener('input', function () {
    const termo = this.value;
    if (termo.length < 2) { document.getElementById('resultados-busca').innerHTML = ''; return; }
    fetch('/caixa/ajax/buscar_produtos.php?termo=' + encodeURIComponent(termo))
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('resultados-busca');
            container.innerHTML = '';
            data.produtos.forEach(p => {
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(p.nome_completo + ' — R$ ' + p.preco.toFixed(2).replace('.', ',') + ' (estoque: ' + p.estoque + ') '));
                const btn = document.createElement('button');
                btn.textContent = 'Adicionar';
                btn.addEventListener('click', function () { adicionarItem(p.id_produto_variacao); });
                div.appendChild(btn);
                container.appendChild(div);
            });
        });
});

function adicionarItem(idProdutoVariacao) {
    fetch('/caixa/ajax/adicionar_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_venda=' + idVenda + '&id_produto_variacao=' + idProdutoVariacao + '&quantidade=1'
    }).then(r => r.json()).then(data => {
        if (data.success) { carregarCarrinho(); } else { alert(data.message); }
    });
}

function removerItem(idItem) {
    fetch('/caixa/ajax/remover_item.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_item=' + idItem + '&id_venda=' + idVenda
    }).then(r => r.json()).then(data => {
        if (data.success) { carregarCarrinho(); } else { alert(data.message); }
    });
}

function carregarCarrinho() {
    fetch('/caixa/ajax/ver_carrinho.php?id_venda=' + idVenda)
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('carrinho');
            container.innerHTML = '';
            data.itens.forEach(item => {
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(
                    item.quantidade + 'x ' + item.nome_produto +
                    (item.descricao_combinacao ? ' (' + item.descricao_combinacao + ')' : '') +
                    ' — R$ ' + item.subtotal.toFixed(2).replace('.', ',') + ' '
                ));
                const btn = document.createElement('button');
                btn.textContent = 'remover';
                btn.addEventListener('click', function () { removerItem(item.id_item); });
                div.appendChild(btn);
                container.appendChild(div);
            });
            document.getElementById('total-venda').textContent = data.total.toFixed(2).replace('.', ',');
        });
}

document.getElementById('termo-cliente').addEventListener('input', function () {
    const termo = this.value;
    if (termo.length < 2) { document.getElementById('resultados-cliente').innerHTML = ''; return; }
    fetch('/caixa/ajax/buscar_clientes.php?termo=' + encodeURIComponent(termo))
        .then(r => r.json())
        .then(data => {
            const container = document.getElementById('resultados-cliente');
            container.innerHTML = '';
            data.clientes.forEach(c => {
                const div = document.createElement('div');
                div.appendChild(document.createTextNode(c.nome + ' (' + c.whatsapp + ') '));
                const btn = document.createElement('button');
                btn.textContent = 'vincular';
                btn.addEventListener('click', function () { vincularCliente(c.id_cliente, c.nome); });
                div.appendChild(btn);
                container.appendChild(div);
            });
        });
});

function vincularCliente(idCliente, nome) {
    fetch('/caixa/ajax/vincular_cliente.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_venda=' + idVenda + '&id_cliente=' + idCliente
    }).then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('cliente-vinculado').textContent = 'Cliente: ' + nome;
        } else {
            alert(data.message);
        }
    });
}

carregarCarrinho();
</script>
    <?php endif; ?>
</body>
</html>
