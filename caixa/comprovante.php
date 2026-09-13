<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
exigirLogin();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$stmt = $pdo->prepare(
    "SELECT v.*, c.nome AS cliente_nome, c.whatsapp AS cliente_whatsapp
     FROM vendas v
     LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
     WHERE v.id_venda = :id AND v.status = 'Pago'"
);
$stmt->execute([':id' => $id_venda]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Venda não encontrada.';
    exit;
}

$itens = $pdo->prepare('SELECT * FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

$pagamentos = $pdo->prepare('SELECT * FROM venda_pagamentos WHERE id_venda = :id');
$pagamentos->execute([':id' => $id_venda]);
$listaPagamentos = $pagamentos->fetchAll();

$linkRecibo = 'https://' . $_SERVER['HTTP_HOST'] . '/caixa/recibo.php?id_venda=' . $id_venda . '&t=' . $venda['token_recibo'];
$whatsappPreenchido = $venda['cliente_whatsapp'] ? preg_replace('/\D/', '', $venda['cliente_whatsapp']) : '';

// Impressão automática só faz sentido chegando direto de uma venda que
// acabou de ser finalizada (redirect de finalizarVenda() traz &novo=1) —
// olhar uma venda antiga na lista (caixa/vendas.php) nunca deve disparar
// impressão sozinha.
$ehVendaNova = isset($_GET['novo']);
$imprimirAutomatico = $ehVendaNova && (bool) $pdo->query('SELECT imprimir_automatico FROM config_loja WHERE id_config = 1')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Comprovante — Venda #<?= $id_venda ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.5 14.5-4-4 1.4-1.4 2.6 2.6 6.6-6.6 1.4 1.4Z" fill="currentColor"/></svg></span>
        <div>
            <h1><?= $ehVendaNova ? 'Venda #' . $id_venda . ' finalizada' : 'Comprovante — Venda #' . $id_venda ?></h1>
            <span class="subtitulo"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($venda['data_venda']))) ?><?= $venda['cliente_nome'] ? ' · ' . htmlspecialchars($venda['cliente_nome']) : ' · Consumidor' ?></span>
        </div>
    </div>

    <?php if (!$ehVendaNova): ?>
    <p class="acoes-topo">
        <a href="/caixa/vendas.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar pra Vendas do caixa
        </a>
    </p>
    <?php endif; ?>

    <div class="card" style="max-width:520px;">
        <h2>Resumo da venda</h2>
        <div class="lista-carrinho">
            <?php foreach ($listaItens as $item): ?>
            <div class="linha-carrinho">
                <div class="linha-carrinho-info">
                    <strong><?= (int) $item['quantidade'] ?>x <?= htmlspecialchars($item['nome_produto']) ?></strong>
                    <?php if ($item['descricao_combinacao']): ?><span class="linha-carrinho-meta"><?= htmlspecialchars($item['descricao_combinacao']) ?></span><?php endif; ?>
                </div>
                <span class="linha-carrinho-subtotal">R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="carrinho-rodape">
            <span>Total</span>
            <strong>R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></strong>
        </div>

        <h3 style="margin-top:28px;">Pagamento</h3>
        <div class="lista-pills">
            <?php foreach ($listaPagamentos as $pag): ?>
            <span class="status-pill"><?= htmlspecialchars($pag['forma_pagamento']) ?>: R$ <?= number_format($pag['valor'], 2, ',', '.') ?></span>
            <?php endforeach; ?>
        </div>

        <div style="display:flex; flex-direction:column; gap:10px; margin-top:24px;">
            <button type="button" class="btn-bloco" id="btn-compartilhar">Compartilhar via WhatsApp</button>
            <button type="button" class="btn-outline btn-bloco" id="btn-imprimir">Imprimir comprovante</button>
            <a href="/caixa/index.php" class="btn-outline btn-bloco" style="text-align:center;">Nova venda</a>
        </div>
    </div>

    <div class="modal-overlay" id="modal-whatsapp" hidden>
        <div class="modal-card">
            <h3>Compartilhar via WhatsApp</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Confira o número antes de enviar — o cliente pode pedir pra mandar pra outro número na hora.</p>
            <input type="text" id="numero-whatsapp" placeholder="Número com DDD, ex: 11987654321" value="<?= htmlspecialchars($whatsappPreenchido) ?>">
            <p id="erro-whatsapp" class="alert alert-erro" style="display:none; margin-top:10px;"></p>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-whatsapp">Cancelar</button>
                <button type="button" class="btn" id="btn-enviar-whatsapp">Enviar</button>
            </div>
        </div>
    </div>

<script>
(function () {
    const modal = document.getElementById('modal-whatsapp');
    const campoNumero = document.getElementById('numero-whatsapp');
    const erro = document.getElementById('erro-whatsapp');
    const linkRecibo = <?= json_encode($linkRecibo) ?>;
    const idVenda = <?= $id_venda ?>;

    document.getElementById('btn-compartilhar').addEventListener('click', function () {
        erro.style.display = 'none';
        modal.hidden = false;
    });
    document.getElementById('btn-cancelar-whatsapp').addEventListener('click', function () { modal.hidden = true; });
    modal.addEventListener('click', function (e) { if (e.target === modal) { modal.hidden = true; } });

    document.getElementById('btn-enviar-whatsapp').addEventListener('click', function () {
        let numero = campoNumero.value.replace(/\D/g, '');
        if (numero.length < 10) {
            erro.textContent = 'Número inválido. Digite DDD + número.';
            erro.style.display = '';
            return;
        }
        if (!numero.startsWith('55')) {
            numero = '55' + numero;
        }
        const texto = 'Aqui está o comprovante da sua compra #' + idVenda + ':\n' + linkRecibo;
        window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(texto), '_blank');
        modal.hidden = true;
    });

    function abrirImpressao() {
        window.open('/caixa/recibo.php?id_venda=' + idVenda + '&t=<?= urlencode($venda['token_recibo']) ?>&print=1', '_blank');
    }

    document.getElementById('btn-imprimir').addEventListener('click', abrirImpressao);

    <?php if ($imprimirAutomatico): ?>
    abrirImpressao();
    <?php endif; ?>
})();
</script>
</main>
</body>
</html>
