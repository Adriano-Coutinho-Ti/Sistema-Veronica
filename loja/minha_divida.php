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
<head><meta charset="UTF-8"><title>Meus débitos</title></head>
<body>
    <p><a href="/loja/index.php">Voltar pra loja</a></p>
    <h1>Meus débitos</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <p>Limite de crédito: R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></p>
    <p>Saldo devedor: R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></p>
    <p>Crédito disponível: R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></p>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h2>Pagar dívida</h2>
    <form method="post" action="/loja/ajax/gerar_checkout_divida.php">
        <label>Valor a pagar
            <input type="text" name="valor" placeholder="0,00" value="<?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?>">
        </label>
        <button type="submit">Pagar com Mercado Pago</button>
    </form>
    <?php endif; ?>

    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p>Nenhum movimento ainda.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($extrato as $mov): ?>
        <li>
            <?= htmlspecialchars($mov['data_movimento']) ?> —
            <?= $mov['tipo'] === 'compra' ? 'Compra fiada' : 'Pagamento' ?>
            (<?= htmlspecialchars($mov['status']) ?>) —
            R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
            <?php if ($mov['tipo'] === 'pagamento' && $mov['status'] !== 'Confirmado'): ?>
                <br><em>Pagamento não confirmado. A loja vai verificar e ajustar seu saldo.</em>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</body>
</html>
