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
    "SELECT tipo, status, valor, forma_pagamento, data_movimento
     FROM movimentos_credito
     WHERE id_cliente = :id
     ORDER BY data_movimento DESC"
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
    <h1>Meus débitos</h1>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="saldo-hero">
        <div class="rotulo">Saldo devedor</div>
        <div class="valor">R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></div>
        <div class="linha"><span>Limite de crédito</span><span>R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></span></div>
        <div class="linha"><span>Crédito disponível</span><span>R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></span></div>
    </div>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h2>Pagar dívida</h2>
    <form method="post" action="/loja/ajax/gerar_checkout_divida.php">
        <label>Valor a pagar
            <input type="text" name="valor" placeholder="0,00" value="<?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?>">
        </label>
        <button type="submit" class="btn-lg btn-bloco">🔒 Pagar com Mercado Pago</button>
    </form>
    <p class="pagamento-seguro">🔒 Pagamento processado com segurança pelo Mercado Pago — seus dados de cartão nunca passam pelo nosso sistema.</p>
    <?php endif; ?>

    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p>Nenhum movimento ainda.</p>
    <?php else: ?>
    <div>
        <?php foreach ($extrato as $mov): ?>
        <div class="extrato-item">
            <div>
                <div class="desc"><?= $mov['tipo'] === 'compra' ? 'Compra fiada' : 'Pagamento' ?></div>
                <div class="data"><?= htmlspecialchars(date('d/m/Y H:i', strtotime($mov['data_movimento']))) ?><?php if ($mov['tipo'] === 'pagamento' && $mov['status'] !== 'Confirmado'): ?> — aguardando confirmação<?php endif; ?></div>
            </div>
            <div class="valor<?= $mov['tipo'] === 'pagamento' ? ' pagamento' : '' ?>">
                <?= $mov['tipo'] === 'pagamento' ? '−' : '+' ?> R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
