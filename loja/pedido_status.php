<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/pedidos.php';
exigirClienteLogado();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT v.*, fe.tipo AS entrega_tipo, fe.nome AS entrega_nome
     FROM vendas v
     LEFT JOIN formas_entrega fe ON fe.id_entrega = v.id_entrega
     WHERE v.id_venda = :id AND v.id_cliente = :ic AND v.origem = 'loja'"
);
$stmt->execute([':id' => $id_venda, ':ic' => $id_cliente]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Pedido não encontrado.';
    exit;
}

$itens = $pdo->prepare(
    "SELECT iv.nome_produto, iv.descricao_combinacao, iv.quantidade, iv.subtotal,
            (SELECT pf.caminho_arquivo FROM produto_fotos pf WHERE pf.id_produto = pv.id_produto ORDER BY pf.ordem LIMIT 1) AS foto
     FROM itens_venda iv
     LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
     WHERE iv.id_venda = :id"
);
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

$rotulo = rotuloStatusPedido($venda['status'], $venda['status_entrega'], $venda['entrega_tipo']);
$classePill = $venda['status'] === 'Cancelado' ? 'cancelado' : ($venda['status_entrega'] === 'Entregue' ? 'concluido' : '');
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pedido #<?= $id_venda ?></title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/meus_pedidos.php" class="btn-texto">← Meus pedidos</a></p>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 6.5V17.5L12 22l9-4.5V6.5L12 2Zm0 2.24 6.24 3.12L12 10.48 5.76 7.36 12 4.24ZM5 9.12l6 3v7.53l-6-3V9.12Zm14 0v7.53l-6 3V12.12l6-3Z" fill="currentColor"/></svg></span>
        <div>
            <h1>Pedido #<?= $id_venda ?></h1>
            <span class="subtitulo"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($venda['data_venda']))) ?></span>
        </div>
    </div>

    <span class="status-pill<?= $classePill ? ' ' . $classePill : '' ?>" style="font-size:0.9rem; padding:8px 16px;"><?= htmlspecialchars($rotulo) ?></span>

    <?php if ($venda['status'] === 'Cancelado'): ?>
        <p class="alert alert-erro" style="margin-top:16px;">Item não liberado. Demora no pagamento. Se você já pagou, a loja entrará em contato pra resolver (reembolso ou reposição).</p>
    <?php elseif ($venda['status'] === 'Reservado'): ?>
        <p style="margin-top:16px;">Aguardando confirmação do pagamento...</p>
        <script>setTimeout(function () { window.location.reload(); }, 5000);</script>
    <?php endif; ?>

    <?php if (!empty($listaItens)): ?>
    <h2 style="margin-top:28px;">Itens</h2>
    <div>
        <?php foreach ($listaItens as $it): ?>
        <div class="carrinho-item">
            <div class="foto">
                <?php if ($it['foto']): ?>
                    <img src="/<?= htmlspecialchars($it['foto']) ?>" alt="<?= htmlspecialchars($it['nome_produto']) ?>">
                <?php endif; ?>
            </div>
            <div class="info">
                <div class="nome"><?= htmlspecialchars($it['nome_produto']) ?></div>
                <?php if ($it['descricao_combinacao']): ?>
                    <div class="variacao"><?= htmlspecialchars($it['descricao_combinacao']) ?></div>
                <?php endif; ?>
                <div class="linha-controle">
                    <span><?= (int) $it['quantidade'] ?>x</span>
                    <span class="subtotal">R$ <?= number_format($it['subtotal'], 2, ',', '.') ?></span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if ($venda['entrega_nome']): ?>
    <p style="margin-top:12px; color:var(--cor-texto-suave);">Entrega: <?= htmlspecialchars($venda['entrega_nome']) ?></p>
    <?php endif; ?>
    <div class="resumo-total"><span>Total</span><span>R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></span></div>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
