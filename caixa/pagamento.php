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
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Pagamento</h1>
            <span class="subtitulo">Venda #<?= $id_venda ?><?= $clienteVinculado ? ' · ' . htmlspecialchars($clienteVinculado['nome']) : '' ?></span>
        </div>
    </div>

    <div class="card" style="max-width:560px;">
        <div class="carrinho-rodape" style="padding-top:0; margin-top:0; border-top:none;">
            <span>Total da venda</span>
            <strong>R$ <span id="total-venda"><?= number_format($venda['valor_total'], 2, ',', '.') ?></span></strong>
        </div>

        <p id="msg-pagamento" class="alert alert-erro" style="display:none; margin-top:16px;"></p>

        <div id="pagamentos-lancados" class="lista-pills" style="margin-top:16px;"></div>

        <label style="margin-top:16px;">Forma de pagamento
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

        <div id="campos-manual" class="form-linha-compacta" style="margin-top:14px;">
            <input type="text" id="valor-pagamento" placeholder="Valor recebido">
            <button type="button" id="btn-adicionar-pagamento" class="btn-outline">Adicionar</button>
        </div>
        <p id="troco-aviso" class="alert alert-sucesso" style="display:none; margin-top:12px;"></p>

        <div id="campos-pix" style="display:none; margin-top:14px;">
            <button type="button" id="btn-gerar-pix" class="btn-outline">Gerar QR Code Pix</button>
            <div id="pix-resultado" style="margin-top:14px;"></div>
        </div>

        <div class="stats-credito" style="margin-top:20px;">
            <div class="stat-credito">
                <span class="stat-label">Pago</span>
                <span class="stat-valor sucesso">R$ <span id="total-pago">0,00</span></span>
            </div>
            <div class="stat-credito">
                <span class="stat-label">Restante</span>
                <span class="stat-valor" id="stat-restante">R$ <span id="total-restante"><?= number_format($venda['valor_total'], 2, ',', '.') ?></span></span>
            </div>
        </div>

        <button type="button" id="btn-finalizar" class="btn-bloco btn-lg" style="display:none; margin-top:20px;">Finalizar venda</button>
    </div>

<script>
const idVenda = <?= $id_venda ?>;
const totalVenda = <?= (float) $venda['valor_total'] ?>;
let pagamentos = [];
let pollingInterval = null;

function mostrarErroPagamento(mensagem) {
    const msg = document.getElementById('msg-pagamento');
    msg.textContent = mensagem;
    msg.style.display = '';
}

document.getElementById('forma-pagamento').addEventListener('change', function () {
    const ehPix = this.value === 'Pix';
    document.getElementById('campos-manual').style.display = ehPix ? 'none' : '';
    document.getElementById('campos-pix').style.display = ehPix ? '' : 'none';
});

document.getElementById('btn-adicionar-pagamento').addEventListener('click', function () {
    document.getElementById('msg-pagamento').style.display = 'none';

    const forma = document.getElementById('forma-pagamento').value;
    const valorRecebido = parseFloat(document.getElementById('valor-pagamento').value.replace(',', '.'));
    if (!valorRecebido || valorRecebido <= 0) { mostrarErroPagamento('Informe um valor válido.'); return; }

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
    } else if (valorRecebido > restante + 0.001) {
        // Só dinheiro pode "sobrar" (vira troco) — cartão/Pix/Linha de Crédito
        // não tem troco: cobrar mais que o restante da venda é sempre um erro
        // de digitação e nunca deveria ser aceito (ex: lançar R$100 de Linha
        // de Crédito numa venda de R$32 criaria uma dívida sem motivo real).
        mostrarErroPagamento('Esse valor passa do restante da venda (R$ ' + restante.toFixed(2).replace('.', ',') + '). Só dinheiro pode receber um valor maior — o troco é calculado sozinho.');
        return;
    } else {
        pagamentos.push({ forma: forma, valor: valorRecebido });
        trocoAviso.style.display = 'none';
    }

    document.getElementById('valor-pagamento').value = '';
    atualizarResumo();
});

function removerPagamento(indice) {
    pagamentos.splice(indice, 1);
    document.getElementById('troco-aviso').style.display = 'none';
    atualizarResumo();
}

function atualizarResumo() {
    const totalPago = pagamentos.reduce((acc, p) => acc + p.valor, 0);
    const restante = Math.max(0, totalVenda - totalPago);
    document.getElementById('total-pago').textContent = totalPago.toFixed(2).replace('.', ',');
    document.getElementById('total-restante').textContent = restante.toFixed(2).replace('.', ',');
    document.getElementById('stat-restante').className = 'stat-valor' + (restante > 0 ? ' erro' : ' sucesso');

    const painel = document.getElementById('pagamentos-lancados');
    painel.innerHTML = '';
    pagamentos.forEach((p, indice) => {
        const pill = document.createElement('span');
        pill.className = 'status-pill pill-removivel';
        const texto = document.createElement('span');
        texto.textContent = p.forma + ': R$ ' + p.valor.toFixed(2).replace('.', ',');
        const btnRemover = document.createElement('button');
        btnRemover.type = 'button';
        btnRemover.setAttribute('aria-label', 'Remover este pagamento');
        btnRemover.textContent = '×';
        btnRemover.addEventListener('click', function () { removerPagamento(indice); });
        pill.appendChild(texto);
        pill.appendChild(btnRemover);
        painel.appendChild(pill);
    });

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
            mostrarErroPagamento(data.message);
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
        if (!data.success) { mostrarErroPagamento(data.message); document.getElementById('btn-gerar-pix').disabled = false; return; }
        const div = document.getElementById('pix-resultado');
        div.innerHTML = '';
        const img = document.createElement('img');
        img.src = 'data:image/png;base64,' + data.qr_code_base64;
        img.width = 200;
        div.appendChild(img);
        const textarea = document.createElement('textarea');
        textarea.readOnly = true;
        textarea.style.width = '100%';
        textarea.style.marginTop = '10px';
        textarea.value = data.qr_code;
        div.appendChild(textarea);
        const p = document.createElement('p');
        p.className = 'lista-vazia';
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
                mostrarErroPagamento(data.message);
            }
        });
    }, 4000);
}
</script>
</main>
</body>
</html>
