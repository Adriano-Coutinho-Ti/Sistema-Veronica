<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_venda = :id AND status = 'Pago'");
$stmt->execute([':id' => $id_venda]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Venda não encontrada.';
    exit;
}

$itens = $pdo->prepare('SELECT * FROM itens_venda WHERE id_venda = :id');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

$pagamentos = $pdo->prepare('SELECT * FROM venda_pagamentos WHERE id_venda = :id');
$pagamentos->execute([':id' => $id_venda]);
$listaPagamentos = $pagamentos->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Comprovante</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Venda #<?= $id_venda ?> — <span class="status-pill sucesso"><?= htmlspecialchars($venda['status']) ?></span></h1>
    <div class="card" style="max-width:480px;">
    <ul class="lista-extrato">
        <?php foreach ($listaItens as $item): ?>
        <li><?= (int) $item['quantidade'] ?>x <?= htmlspecialchars($item['nome_produto']) ?>
            <?= $item['descricao_combinacao'] ? '(' . htmlspecialchars($item['descricao_combinacao']) . ')' : '' ?>
            — R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></li>
        <?php endforeach; ?>
    </ul>
    <p style="font-weight:700;">Total: R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></p>
    <h3>Pagamentos</h3>
    <ul class="lista-extrato">
        <?php foreach ($listaPagamentos as $pag): ?>
        <li><?= htmlspecialchars($pag['forma_pagamento']) ?>: R$ <?= number_format($pag['valor'], 2, ',', '.') ?></li>
        <?php endforeach; ?>
    </ul>
    </div>
    <p><a href="/caixa/index.php" class="btn">Nova venda</a></p>
</main>
</body>
</html>
