<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    echo 'Cliente não encontrado.';
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_limite') {
    if (($_SESSION['perfil'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }

    $novoLimite = (float) str_replace(',', '.', $_POST['limite_credito'] ?? '0');
    if ($novoLimite < 0) {
        $erro = 'Limite inválido.';
    } else {
        $pdo->prepare('UPDATE clientes SET limite_credito = :limite WHERE id_cliente = :id')
            ->execute([':limite' => $novoLimite, ':id' => $id]);
        $sucesso = 'Limite atualizado.';
        $cliente['limite_credito'] = $novoLimite;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'registrar_pagamento') {
    $valor = (float) str_replace(',', '.', $_POST['valor'] ?? '0');
    $forma = trim($_POST['forma_pagamento'] ?? '');
    $formasValidas = ['Dinheiro', 'Débito', 'Crédito', 'Pix'];

    if ($valor <= 0 || !in_array($forma, $formasValidas, true)) {
        $erro = 'Dados de pagamento inválidos.';
    } else {
        try {
            $pdo->beginTransaction();

            $stmtAbater = $pdo->prepare(
                'UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor)
                 WHERE id_cliente = :id AND saldo_devedor >= :valor2'
            );
            $stmtAbater->execute([':valor' => $valor, ':valor2' => $valor, ':id' => $id]);

            if ($stmtAbater->rowCount() === 0) {
                throw new Exception('Valor maior que a dívida atual.');
            }

            $pdo->prepare(
                "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, forma_pagamento, criado_por)
                 VALUES (:ic, 'pagamento', 'Confirmado', :valor, :forma, :criado_por)"
            )->execute([
                ':ic' => $id,
                ':valor' => $valor,
                ':forma' => $forma,
                ':criado_por' => $_SESSION['id_usuario'],
            ]);

            $pdo->commit();
            $sucesso = 'Pagamento registrado com sucesso.';

            $stmt2 = $pdo->prepare('SELECT saldo_devedor FROM clientes WHERE id_cliente = :id');
            $stmt2->execute([':id' => $id]);
            $cliente['saldo_devedor'] = $stmt2->fetchColumn();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $erro = $e->getMessage();
        }
    }
}

$stmtExtrato = $pdo->prepare(
    'SELECT mc.*, u.nome AS nome_funcionario
     FROM movimentos_credito mc
     LEFT JOIN usuarios u ON u.id_usuario = mc.criado_por
     WHERE mc.id_cliente = :id
     ORDER BY mc.data_movimento DESC'
);
$stmtExtrato->execute([':id' => $id]);
$extrato = $stmtExtrato->fetchAll();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($cliente['nome']) ?></title></head>
<body>
    <h1><?= htmlspecialchars($cliente['nome']) ?></h1>
    <p>WhatsApp: <?= htmlspecialchars($cliente['whatsapp']) ?></p>
    <p>E-mail: <?= htmlspecialchars($cliente['email'] ?? '—') ?></p>
    <p>Endereço: <?= htmlspecialchars($cliente['endereco'] ?? '—') ?></p>

    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p style="color:green;"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <h2>Linha de Crédito</h2>
    <p>Limite: R$ <?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?></p>
    <p>Saldo devedor: R$ <?= number_format((float) $cliente['saldo_devedor'], 2, ',', '.') ?></p>
    <p>Crédito disponível: R$ <?= number_format($creditoDisponivel, 2, ',', '.') ?></p>

    <?php if (($_SESSION['perfil'] ?? '') === 'Admin'): ?>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar_limite">
        <label>Novo limite de crédito
            <input type="text" name="limite_credito" value="<?= number_format((float) $cliente['limite_credito'], 2, ',', '.') ?>">
        </label>
        <button type="submit">Atualizar limite</button>
    </form>
    <?php endif; ?>

    <?php if ((float) $cliente['saldo_devedor'] > 0): ?>
    <h3>Registrar pagamento da dívida</h3>
    <form method="post">
        <input type="hidden" name="acao" value="registrar_pagamento">
        <label>Valor recebido
            <input type="text" name="valor" placeholder="0,00">
        </label>
        <label>Forma de pagamento
            <select name="forma_pagamento">
                <option value="Dinheiro">Dinheiro</option>
                <option value="Débito">Débito</option>
                <option value="Crédito">Crédito</option>
                <option value="Pix">Pix</option>
            </select>
        </label>
        <button type="submit">Registrar pagamento</button>
    </form>
    <?php endif; ?>

    <h2>Extrato</h2>
    <?php if (empty($extrato)): ?>
    <p>Nenhum movimento de crédito ainda.</p>
    <?php else: ?>
    <ul>
        <?php foreach ($extrato as $mov): ?>
        <li>
            <?= htmlspecialchars($mov['data_movimento']) ?> —
            <?= $mov['tipo'] === 'compra' ? 'Compra fiada' : 'Pagamento' ?>
            (<?= htmlspecialchars($mov['status']) ?>) —
            R$ <?= number_format((float) $mov['valor'], 2, ',', '.') ?>
            <?= $mov['forma_pagamento'] ? ' via ' . htmlspecialchars($mov['forma_pagamento']) : '' ?>
            <?= $mov['nome_funcionario'] ? ' (registrado por ' . htmlspecialchars($mov['nome_funcionario']) . ')' : ' (cliente, online)' ?>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <p><a href="/clientes/lista.php">Voltar</a></p>
</body>
</html>
