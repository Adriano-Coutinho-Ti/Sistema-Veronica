<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$clientes = $pdo->query('SELECT id_cliente, nome, whatsapp, email FROM clientes ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clientes</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Clientes</h1>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Cliente cadastrado com sucesso.</p><?php endif; ?>
    <p><a href="/clientes/novo.php">+ Novo cliente</a></p>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th></th></tr>
        <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['nome']) ?></td>
            <td><?= htmlspecialchars($c['whatsapp']) ?></td>
            <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
            <td><a href="/clientes/detalhe.php?id=<?= $c['id_cliente'] ?>">ver</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</main>
</body>
</html>
