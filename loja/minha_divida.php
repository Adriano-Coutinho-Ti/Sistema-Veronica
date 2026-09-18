<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$stmtCliente = $pdo->prepare('SELECT limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];

const EXTRATO_POR_PAGINA = 10;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotalExtrato = $pdo->prepare('SELECT COUNT(*) FROM movimentos_credito WHERE id_cliente = :id');
$stmtTotalExtrato->execute([':id' => $id_cliente]);
$totalMovimentos = (int) $stmtTotalExtrato->fetchColumn();
$totalPaginasExtrato = max(1, (int) ceil($totalMovimentos / EXTRATO_POR_PAGINA));
$pagina = min($pagina, $totalPaginasExtrato);
$offsetExtrato = ($pagina - 1) * EXTRATO_POR_PAGINA;

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
     ORDER BY mc.data_movimento DESC
     LIMIT :limite OFFSET :offset"
);
$stmtExtrato->bindValue(':id', $id_cliente, PDO::PARAM_INT);
$stmtExtrato->bindValue(':limite', EXTRATO_POR_PAGINA, PDO::PARAM_INT);
$stmtExtrato->bindValue(':offset', $offsetExtrato, PDO::PARAM_INT);
$stmtExtrato->execute();
$extrato = $stmtExtrato->fetchAll();

$stmtSolicitacao = $pdo->prepare(
    'SELECT id_solicitacao, valor_solicitado, status, valor_aprovado, observacao_admin, criado_em
     FROM solicitacoes_credito WHERE id_cliente = :id ORDER BY criado_em DESC LIMIT 1'
);
$stmtSolicitacao->execute([':id' => $id_cliente]);
$ultimaSolicitacao = $stmtSolicitacao->fetch();
$temSolicitacaoPendente = $ultimaSolicitacao && $ultimaSolicitacao['status'] === 'Pendente';

$erro = $_GET['erro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Meus débitos</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/index.php" class="btn-texto">← Voltar pra loja</a></p>
    <div class="banner-hero">
        <h1>Meus débitos</h1>
        <p>Acompanhe seu saldo e pague com segurança quando quiser.</p>
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
                            <img src="/<?= htmlspecialchars(fotoComVersao($mov['produto_foto'])) ?>" alt="">
                        <?php else: ?>
                            <img src="/assets/img/produto-indisponivel.svg" alt="Produto indisponível">
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

            <?php if ($totalPaginasExtrato > 1): ?>
            <nav class="paginacao">
                <?php if ($pagina > 1): ?><a href="/loja/minha_divida.php?pagina=<?= $pagina - 1 ?>">‹ Anterior</a><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPaginasExtrato; $p++): ?>
                    <a href="/loja/minha_divida.php?pagina=<?= $p ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginasExtrato): ?><a href="/loja/minha_divida.php?pagina=<?= $pagina + 1 ?>">Próxima ›</a><?php endif; ?>
            </nav>
            <?php endif; ?>
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
                        <input type="text" name="valor" class="js-mascara-moeda" placeholder="0,00" value="<?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?>">
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

            <?php if ((float) $cliente['limite_credito'] <= 0): ?>
            <div class="resumo-card">
                <h2>Linha de crédito</h2>
                <?php if (isset($_GET['solicitado'])): ?>
                    <p class="alert alert-sucesso">Solicitação enviada! A loja vai analisar e responder em breve.</p>
                <?php endif; ?>

                <?php if ($temSolicitacaoPendente): ?>
                    <p>Sua solicitação de R$ <?= number_format($ultimaSolicitacao['valor_solicitado'], 2, ',', '.') ?>, enviada em <?= htmlspecialchars(date('d/m/Y', strtotime($ultimaSolicitacao['criado_em']))) ?>, está em análise.</p>
                <?php else: ?>
                    <?php if ($ultimaSolicitacao && $ultimaSolicitacao['status'] === 'Rejeitada'): ?>
                        <p class="alert alert-erro">Sua última solicitação não foi aprovada<?= $ultimaSolicitacao['observacao_admin'] ? ': ' . htmlspecialchars($ultimaSolicitacao['observacao_admin']) : '.' ?></p>
                    <?php endif; ?>
                    <p>Você ainda não tem uma linha de crédito na loja. Solicite abaixo pra poder comprar a prazo.</p>
                    <form method="post" action="/loja/ajax/solicitar_credito.php">
                        <label>Valor desejado
                            <input type="text" name="valor_solicitado" class="js-mascara-moeda" placeholder="0,00" required>
                        </label>
                        <button type="submit" class="btn-outline btn-bloco">Solicitar linha de crédito</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
