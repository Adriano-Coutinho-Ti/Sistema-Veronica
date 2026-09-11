<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_venda = :id AND id_usuario = :iu AND status = 'Reservado'");
$stmt->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
$venda = $stmt->fetch();

if (!$venda) {
    header('Location: /caixa/index.php');
    exit;
}

$clienteVinculado = null;
$creditoDisponivel = 0.0;
if ($venda['id_cliente']) {
    $stmtCli = $pdo->prepare('SELECT nome, limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
    $stmtCli->execute([':id' => $venda['id_cliente']]);
    $clienteVinculado = $stmtCli->fetch();
    if ($clienteVinculado) {
        $creditoDisponivel = (float) $clienteVinculado['limite_credito'] - (float) $clienteVinculado['saldo_devedor'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pagamento</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Pagamento — Venda #<?= $id_venda ?></h1>
    <p>Total: R$ <span id="total-venda"><?= number_format($venda['valor_total'], 2, ',', '.') ?></span></p>

    <div id="pagamentos-lancados"></div>

    <label>Forma de pagamento
        <select id="forma-pagamento">
            <option value="Dinheiro">Dinheiro</option>
            <option value="Débito">Débito</option>
            <option value="Crédito">Crédito</option>
            <option value="Pix">Pix (QR Code)</option>
            <option value="Linha de Crédito" <?= !$clienteVinculado ? 'disabled' : '' ?>>
                Linha de Crédito<?= $clienteVinculado
                    ? ' (disponível: R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')'
                    : ' (vincule um cliente primeiro)' ?>
            </option>
        </select>
    </label>

    <div id="campos-manual">
        <input type="text" id="valor-pagamento" placeholder="Valor recebido">
        <button id="btn-adicionar-pagamento">Adicionar pagamento</button>
        <p id="troco-aviso" style="display:none;"></p>
    </div>

    <div id="campos-pix" style="display:none;">
        <button id="btn-gerar-pix">Gerar QR Code Pix</button>
        <div id="pix-resultado"></div>
    </div>

    <p>Pago: R$ <span id="total-pago">0,00</span> / Restante: R$ <span id="total-restante"><?= number_format($venda['valor_total'], 2, ',', '.') ?></span></p>

    <button id="btn-finalizar" style="display:none;">Finalizar venda</button>

<script>
const idVenda = <?= $id_venda ?>;
const totalVenda = <?= (float) $venda['valor_total'] ?>;
let pagamentos = [];
let pollingInterval = null;

document.getElementById('forma-pagamento').addEventListener('change', function () {
    const ehPix = this.value === 'Pix';
    document.getElementById('campos-manual').style.display = ehPix ? 'none' : '';
    document.getElementById('campos-pix').style.display = ehPix ? '' : 'none';
});

document.getElementById('btn-adicionar-pagamento').addEventListener('click', function () {
    const forma = document.getElementById('forma-pagamento').value;
    const valorRecebido = parseFloat(document.getElementById('valor-pagamento').value.replace(',', '.'));
    if (!valorRecebido || valorRecebido <= 0) { alert('Valor inválido'); return; }

    const totalPagoAtual = pagamentos.reduce((acc, p) => acc + p.valor, 0);
    const restante = Math.max(0, totalVenda - totalPagoAtual);
    const trocoAviso = document.getElementById('troco-aviso');

    if (forma === 'Dinheiro' && valorRecebido > restante) {
        // Só o que ainda falta da venda vira pagamento registrado — o excedente é
        // troco de verdade devolvido ao cliente, nunca dinheiro que fica no caixa.
        const valorAplicado = Math.round(restante * 100) / 100;
        const troco = Math.round((valorRecebido - restante) * 100) / 100;
        pagamentos.push({ forma: forma, valor: valorAplicado });
        trocoAviso.textContent = 'Troco a devolver: R$ ' + troco.toFixed(2).replace('.', ',');
        trocoAviso.style.display = '';
    } else {
        pagamentos.push({ forma: forma, valor: valorRecebido });
        trocoAviso.style.display = 'none';
    }

    document.getElementById('valor-pagamento').value = '';
    atualizarResumo();
});

function atualizarResumo() {
    const totalPago = pagamentos.reduce((acc, p) => acc + p.valor, 0);
    document.getElementById('total-pago').textContent = totalPago.toFixed(2).replace('.', ',');
    document.getElementById('total-restante').textContent = Math.max(0, totalVenda - totalPago).toFixed(2).replace('.', ',');
    document.getElementById('pagamentos-lancados').textContent = pagamentos.map(p => p.forma + ': R$ ' + p.valor.toFixed(2).replace('.', ',')).join(' | ');
    document.getElementById('btn-finalizar').style.display = totalPago >= totalVenda - 0.001 ? '' : 'none';
}

document.getElementById('btn-finalizar').addEventListener('click', function () {
    fetch('/caixa/ajax/finalizar_venda.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_venda=' + idVenda + '&pagamentos=' + encodeURIComponent(JSON.stringify(pagamentos))
    }).then(r => r.json()).then(data => {
        if (data.success) {
            window.location.href = data.redirect;
        } else {
            alert(data.message);
        }
    });
});

document.getElementById('btn-gerar-pix').addEventListener('click', function () {
    this.disabled = true;
    fetch('/caixa/ajax/gerar_pix.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_venda=' + idVenda
    }).then(r => r.json()).then(data => {
        if (!data.success) { alert(data.message); document.getElementById('btn-gerar-pix').disabled = false; return; }
        const div = document.getElementById('pix-resultado');
        div.innerHTML = '';
        const img = document.createElement('img');
        img.src = 'data:image/png;base64,' + data.qr_code_base64;
        img.width = 200;
        div.appendChild(img);
        const textarea = document.createElement('textarea');
        textarea.readOnly = true;
        textarea.style.width = '300px';
        textarea.value = data.qr_code;
        div.appendChild(textarea);
        const p = document.createElement('p');
        p.textContent = 'Aguardando pagamento...';
        div.appendChild(p);
        iniciarPolling();
    });
});

function iniciarPolling() {
    if (pollingInterval) return;
    pollingInterval = setInterval(function () {
        fetch('/caixa/ajax/verificar_pagamento.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_venda=' + idVenda
        }).then(r => r.json()).then(data => {
            if (data.aprovado) {
                clearInterval(pollingInterval);
                window.location.href = data.redirect;
            } else if (!data.success) {
                clearInterval(pollingInterval);
                pollingInterval = null;
                alert(data.message);
            }
        });
    }, 4000);
}
</script>
</main>
</body>
</html>
