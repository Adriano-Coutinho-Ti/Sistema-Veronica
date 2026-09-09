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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($cliente['nome']) ?></title></head>
<body>
    <h1><?= htmlspecialchars($cliente['nome']) ?></h1>
    <p>WhatsApp: <?= htmlspecialchars($cliente['whatsapp']) ?></p>
    <p>E-mail: <?= htmlspecialchars($cliente['email'] ?? '—') ?></p>
    <p>Endereço: <?= htmlspecialchars($cliente['endereco'] ?? '—') ?></p>
    <p><a href="/clientes/lista.php">Voltar</a></p>
</body>
</html>
