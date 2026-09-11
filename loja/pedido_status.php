<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare("SELECT * FROM vendas WHERE id_venda = :id AND id_cliente = :ic AND origem = 'loja'");
$stmt->execute([':id' => $id_venda, ':ic' => $id_cliente]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Pedido não encontrado.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Status do pedido</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Pedido #<?= $id_venda ?></h1>
    <?php if ($venda['status'] === 'Pago'): ?>
        <p style="color:green;">Pagamento confirmado! Seu pedido já está sendo preparado.</p>
    <?php elseif ($venda['status'] === 'Cancelado'): ?>
        <p style="color:red;">Item não liberado. Demora no pagamento.</p>
        <p>Se você já pagou, a loja entrará em contato pra resolver (reembolso ou reposição).</p>
    <?php else: ?>
        <p>Aguardando confirmação do pagamento...</p>
        <script>setTimeout(function () { window.location.reload(); }, 5000);</script>
    <?php endif; ?>
    <p><a href="/loja/index.php">Voltar pra loja</a></p>
</main>
</body>
</html>
