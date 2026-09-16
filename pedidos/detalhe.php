<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/pedidos.php';
require_once __DIR__ . '/../includes/loja.php';
exigirLogin();

$id_venda = (int) ($_GET['id_venda'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT v.*, c.nome AS cliente_nome, c.whatsapp AS cliente_whatsapp, c.endereco AS cliente_endereco,
            fe.nome AS entrega_nome, fe.tipo AS entrega_tipo,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), v.pagamento_expira_em)) AS segundos_restantes_pagamento
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

$itens = $pdo->prepare(
    'SELECT iv.nome_produto, iv.descricao_combinacao, iv.quantidade, iv.preco_unit, iv.subtotal, p.codigo
     FROM itens_venda iv
     LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
     LEFT JOIN produtos p ON p.id_produto = pv.id_produto
     WHERE iv.id_venda = :id'
);
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

$nomeLoja = $pdo->query('SELECT nome_loja FROM config_loja WHERE id_config = 1')->fetchColumn() ?: 'a loja';
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
    <h3>Dados do Cliente</h3>
    <p>
        <?= htmlspecialchars($pedido['cliente_nome'] ?? '—') ?><br>
        <strong>WhatsApp:</strong> <?= htmlspecialchars(formatarWhatsappExibicao($pedido['cliente_whatsapp'] ?? '')) ?><br>
        <?php if ($pedido['entrega_tipo'] === 'entrega'): ?>
            <strong>Endereço:</strong> <?= htmlspecialchars($pedido['cliente_endereco'] ?? '—') ?><br>
        <?php endif; ?>
        <strong>Entrega:</strong> <?= htmlspecialchars($pedido['entrega_nome'] ?? '—') ?>
    </p>
    </div>

    <div class="card">
    <p style="font-weight:700; font-size:1.1rem;">Total: R$ <?= number_format($pedido['valor_total'], 2, ',', '.') ?></p>
    <p><strong>Pagamento:</strong> <?= htmlspecialchars($pedido['forma_pagamento'] ?? '—') ?></p>
    <?php if ($pedido['status'] === 'Cancelado' && !empty($pedido['motivo_cancelamento'])): ?>
    <p><strong>Motivo do cancelamento:</strong> <?= htmlspecialchars($pedido['motivo_cancelamento']) ?></p>
    <?php endif; ?>
    </div>
    </div>

    <div class="card" style="margin-top:20px;">
    <h3>Itens</h3>
    <div class="tabela-wrap">
    <table>
        <tr><th>Código</th><th>Produto</th><th>Variação</th><th>Qtd</th><th>Preço</th><th>Subtotal</th></tr>
        <?php foreach ($listaItens as $it): ?>
        <tr>
            <td><?= $it['codigo'] ? '<span class="codigo-produto-mini">' . htmlspecialchars($it['codigo']) . '</span>' : '—' ?></td>
            <td><?= htmlspecialchars($it['nome_produto']) ?></td>
            <td><?= htmlspecialchars($it['descricao_combinacao'] ?? '—') ?></td>
            <td><?= (int) $it['quantidade'] ?></td>
            <td>R$ <?= number_format($it['preco_unit'], 2, ',', '.') ?></td>
            <td>R$ <?= number_format($it['subtotal'], 2, ',', '.') ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
    </div>

    <?php if ($pedido['status'] === 'Reservado' && $pedido['pagamento_expira_em'] !== null): ?>
    <div class="card" style="margin-top:20px; max-width:480px;">
    <h3>Pagamento pendente no Mercado Pago</h3>
    <p style="margin-bottom:6px;">
        <span class="status-pill alerta" id="contagem-pagamento-admin" style="font-family:var(--fonte-titulo); font-size:1rem;">--:--</span>
        <span style="color:var(--cor-texto-suave); font-size:0.85rem;"> restantes pra concluir o pagamento</span>
    </p>
    <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">O cliente ainda não concluiu o pagamento. Se ele perdeu o QR Code, envie o link de pagamento de novo pelo WhatsApp.</p>
    <button type="button" class="btn-outline" id="btn-compartilhar-pagamento">
        <svg viewBox="0 0 24 24" aria-hidden="true" width="16" height="16"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
        Compartilhar link no WhatsApp
    </button>
    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
    <button type="button" class="btn-outline btn-perigo" id="btn-cancelar-pagamento" style="margin-top:10px;" data-confirm="Cancelar esse pagamento no Mercado Pago e liberar o estoque reservado?">Cancelar pagamento no Mercado Pago</button>
    <p id="cancelar-pagamento-msg"></p>
    <?php endif; ?>
    </div>

    <div class="modal-overlay" id="modal-compartilhar-pagamento" hidden>
        <div class="modal-card">
            <h3>Compartilhar link pelo WhatsApp</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Confira o número antes de enviar — o WhatsApp abre com a mensagem já pronta pra só conferir e mandar.</p>
            <label>Número<input type="text" id="numero-compartilhar-pagamento" placeholder="Número com DDD" value="<?= htmlspecialchars($pedido['cliente_whatsapp'] ?? '') ?>"></label>
            <label>Mensagem<textarea id="texto-compartilhar-pagamento" rows="6"></textarea></label>
            <p id="erro-compartilhar-pagamento" class="alert alert-erro" style="display:none; margin-top:10px;"></p>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-compartilhar-pagamento">Cancelar</button>
                <button type="button" class="btn" id="btn-enviar-compartilhar-pagamento">Abrir WhatsApp</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($pedido['status'] === 'Pago'): ?>
    <div class="card" style="margin-top:20px;">
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
        <div class="card" style="margin-top:20px;">
        <h3>Cancelar pedido</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Devolve o estoque dos itens e, se parte do pagamento foi em Linha de Crédito, estorna o saldo devedor do cliente. Pagamento em dinheiro/cartão/Pix precisa ser reembolsado por fora.</p>
        <button id="btn-cancelar" class="btn-perigo">Cancelar este pedido</button>
        <p id="cancelar-msg"></p>
        </div>

        <div class="modal-overlay" id="modal-cancelar" hidden>
            <div class="modal-card">
                <h3>Cancelar pedido</h3>
                <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Estoque e crédito (se houver) serão estornados. Informe o motivo do cancelamento:</p>
                <button type="button" class="btn-sm btn-outline" id="btn-motivo-atraso" style="margin-bottom:10px;">O cliente não pagou a tempo</button>
                <textarea id="motivo-cancelamento" placeholder="Motivo do cancelamento"></textarea>
                <p id="erro-motivo" class="alert alert-erro" style="display:none;"></p>
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-modal-cancelar">Voltar</button>
                    <button type="button" class="btn-perigo" id="btn-confirmar-cancelamento">Confirmar cancelamento</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<script>
const idVenda = <?= $id_venda ?>;

const contagemPagamentoAdmin = document.getElementById('contagem-pagamento-admin');
if (contagemPagamentoAdmin) {
    let restantePagamentoAdmin = <?= (int) ($pedido['segundos_restantes_pagamento'] ?? 0) ?>;

    function formatarTempoPagamentoAdmin(segundos) {
        const min = Math.floor(segundos / 60);
        const seg = segundos % 60;
        return min + ':' + String(seg).padStart(2, '0');
    }

    function atualizarContagemPagamentoAdmin() {
        contagemPagamentoAdmin.textContent = formatarTempoPagamentoAdmin(restantePagamentoAdmin);
        if (restantePagamentoAdmin > 0) { restantePagamentoAdmin--; }
    }
    atualizarContagemPagamentoAdmin();
    setInterval(atualizarContagemPagamentoAdmin, 1000);
}

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
const modalCancelar = document.getElementById('modal-cancelar');
if (btnCancelar && modalCancelar) {
    const campoMotivo = document.getElementById('motivo-cancelamento');
    const erroMotivo = document.getElementById('erro-motivo');

    function fecharModalCancelar() {
        modalCancelar.hidden = true;
        campoMotivo.value = '';
        erroMotivo.style.display = 'none';
    }

    btnCancelar.addEventListener('click', function () { modalCancelar.hidden = false; });
    document.getElementById('btn-cancelar-modal-cancelar').addEventListener('click', fecharModalCancelar);
    modalCancelar.addEventListener('click', function (e) { if (e.target === modalCancelar) { fecharModalCancelar(); } });
    document.getElementById('btn-motivo-atraso').addEventListener('click', function () {
        campoMotivo.value = 'O cliente não pagou a tempo';
    });

    document.getElementById('btn-confirmar-cancelamento').addEventListener('click', function () {
        const motivo = campoMotivo.value.trim();
        if (!motivo) {
            erroMotivo.textContent = 'Informe o motivo do cancelamento.';
            erroMotivo.style.display = '';
            return;
        }
        fetch('/pedidos/ajax/cancelar.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_venda=' + idVenda + '&motivo=' + encodeURIComponent(motivo)
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                erroMotivo.textContent = data.message;
                erroMotivo.style.display = '';
            }
        });
    });
}

const btnCompartilharPagamento = document.getElementById('btn-compartilhar-pagamento');
if (btnCompartilharPagamento) {
    const nomeLoja = <?= json_encode($nomeLoja) ?>;
    const linkPagamento = <?= json_encode($pedido['link_pagamento_mp'] ?? '') ?>;
    const nomeCliente = <?= json_encode($pedido['cliente_nome'] ?? 'Cliente') ?>;
    const valorPedido = <?= json_encode(number_format((float) $pedido['valor_total'], 2, ',', '.')) ?>;

    const modalCompartilhar = document.getElementById('modal-compartilhar-pagamento');
    const campoNumero = document.getElementById('numero-compartilhar-pagamento');
    const campoTexto = document.getElementById('texto-compartilhar-pagamento');
    const erroCompartilhar = document.getElementById('erro-compartilhar-pagamento');

    function fecharModalCompartilhar() { modalCompartilhar.hidden = true; }

    btnCompartilharPagamento.addEventListener('click', function () {
        erroCompartilhar.style.display = 'none';
        const primeiroNome = nomeCliente.split(' ')[0];
        campoTexto.value = 'Olá, ' + primeiroNome + '! Segue o link pra você concluir o pagamento do seu pedido #' + idVenda + ' (R$ ' + valorPedido + ') na ' + nomeLoja + ':\n\n' + linkPagamento;
        modalCompartilhar.hidden = false;
    });

    document.getElementById('btn-cancelar-compartilhar-pagamento').addEventListener('click', fecharModalCompartilhar);
    modalCompartilhar.addEventListener('click', function (e) { if (e.target === modalCompartilhar) { fecharModalCompartilhar(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modalCompartilhar.hidden) { fecharModalCompartilhar(); } });

    document.getElementById('btn-enviar-compartilhar-pagamento').addEventListener('click', function () {
        let numero = campoNumero.value.replace(/\D/g, '');
        if (numero.length === 10 || numero.length === 11) {
            numero = '55' + numero;
        }
        if (numero.length !== 12 && numero.length !== 13) {
            erroCompartilhar.textContent = 'Número inválido — confira o DDD.';
            erroCompartilhar.style.display = '';
            return;
        }
        window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(campoTexto.value), '_blank');
        fecharModalCompartilhar();
    });
}

const btnCancelarPagamento = document.getElementById('btn-cancelar-pagamento');
if (btnCancelarPagamento) {
    btnCancelarPagamento.addEventListener('click', function () {
        confirmarAcao(btnCancelarPagamento.dataset.confirm).then(function (ok) {
            if (!ok) { return; }
            btnCancelarPagamento.disabled = true;
            const msg = document.getElementById('cancelar-pagamento-msg');
            fetch('/pedidos/ajax/cancelar_pagamento_mp.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_venda=' + idVenda
            }).then(r => r.json()).then(function (data) {
                msg.textContent = data.message;
                msg.className = data.success ? 'alert alert-sucesso' : 'alert alert-erro';
                if (data.success) {
                    window.location.reload();
                } else {
                    btnCancelarPagamento.disabled = false;
                }
            }).catch(function () {
                msg.textContent = 'Erro de conexão. Tente novamente.';
                msg.className = 'alert alert-erro';
                btnCancelarPagamento.disabled = false;
            });
        });
    });
}
</script>
</main>
</body>
</html>
