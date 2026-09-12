<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$stmtCliente = $pdo->prepare('SELECT limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];

$stmtExtrato = $pdo->prepare(
    "SELECT mc.tipo, mc.status, mc.valor, mc.forma_pagamento, mc.data_movimento,
            (SELECT iv.nome_produto FROM itens_venda iv WHERE iv.id_venda = mc.id_venda ORDER BY iv.id_item LIMIT 1) AS produto_nome,
            (SELECT pf.caminho_arquivo
             FROM itens_venda iv
             JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
             JOIN produto_fotos pf ON pf.id_produto = pv.id_produto
             WHERE iv.id_venda = mc.id_venda
             ORDER BY iv.id_item, pf.ordem LIMIT 1) AS produto_foto,
            (SELECT COUNT(*) FROM itens_venda iv WHERE iv.id_venda = mc.id_venda) AS qtd_itens
     FROM movimentos_credito mc
     WHERE mc.id_cliente = :id
     ORDER BY mc.data_movimento DESC"
);
$stmtExtrato->execute([':id' => $id_cliente]);
$extrato = $stmtExtrato->fetchAll();

$erro = $_GET['erro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Meus débitos</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/index.php" class="btn-texto">← Voltar pra loja</a></p>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-4a1.5 1.5 0 0 0 0 3h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <h1>Meus débitos</h1>
    </div>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="layout-colunas">
        <div>
            <h2>Extrato</h2>
            <?php if (empty($extrato)): ?>
            <p>Nenhum movimento ainda.</p>
            <?php else: ?>
            <div>
                <?php foreach ($extrato as $mov): ?>
                <?php
                    $ehCompra = $mov['tipo'] === 'compra';
                    $descricao = $ehCompra ? ($mov['produto_nome'] ?? 'Compra a prazo') : 'Pagamento';
                    if ($ehCompra && (int) $mov['qtd_itens'] > 1) {
                        $descricao .= ' e mais ' . ((int) $mov['qtd_itens'] - 1) . ' item(ns)';
                    }
                ?>
                <div class="extrato-item">
                    <?php if ($ehCompra): ?>
                    <div class="foto">
                        <?php if ($mov['produto_foto']): ?>
                            <img src="/<?= htmlspecialchars($mov['produto_foto']) ?>" alt="">
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="desc-wrap">
                        <div class="desc"><?= htmlspecialchars($descricao) ?></div>
                        <?php if ($ehCompra): ?><div class="variacao">Compra a prazo</div><?php endif; ?>
                        <div class="data"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($mov['data_movimento']))) ?><?php if ($mov['tipo'] === 'pagamento' && $mov['status'] !== 'Confirmado'): ?> — aguardando confirmação<?php endif; ?></div>
                    </div>
                    <div class="valor<?= $mov['tipo'] === 'pagamento' ? ' pagamento' : '' ?>">
                        <?= $mov['tipo'] === 'pagamento' ? '−' : '+' ?> R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="painel-lateral">
            <div class="saldo-hero">
                <div class="rotulo">Saldo devedor</div>
                <div class="valor">R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></div>
                <div class="linha"><span>Limite de crédito</span><span>R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></span></div>
                <div class="linha"><span>Crédito disponível</span><span>R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></span></div>
            </div>

            <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
            <div class="resumo-card">
                <h2>Pagar dívida</h2>
                <form method="post" action="/loja/ajax/gerar_checkout_divida.php">
                    <label>Valor a pagar
                        <input type="text" name="valor" placeholder="0,00" value="<?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?>">
                    </label>
                    <button type="submit" class="btn-lg btn-bloco">
                        <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h1Zm2 0h8V8a4 4 0 1 0-8 0v2Z"/></svg>
                        Pagar com Mercado Pago
                    </button>
                </form>
                <p class="pagamento-seguro">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h1Zm2 0h8V8a4 4 0 1 0-8 0v2Z"/></svg>
                    Pagamento processado com segurança pelo Mercado Pago — seus dados de cartão nunca passam pelo nosso sistema.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
