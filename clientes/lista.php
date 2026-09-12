<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$clientes = $pdo->query('SELECT id_cliente, nome, whatsapp, email, email_verificado_em FROM clientes ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Clientes</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Clientes</h1>
    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Cliente cadastrado com sucesso.</p><?php endif; ?>
    <p><a href="/clientes/novo.php" class="btn">+ Novo cliente</a></p>
    <div class="tabela-wrap">
    <table>
        <tr><th>Nome</th><th>WhatsApp</th><th>E-mail</th><th>E-mail verificado</th><th></th></tr>
        <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['nome']) ?></td>
            <td><?= htmlspecialchars($c['whatsapp']) ?></td>
            <td><?= htmlspecialchars($c['email'] ?? '') ?></td>
            <td>
                <?php if (!$c['email']): ?>—
                <?php elseif ($c['email_verificado_em']): ?><span class="status-pill sucesso">✓ Verificado</span>
                <?php else: ?><span class="status-pill alerta">Não verificado</span>
                <?php endif; ?>
            </td>
            <td><a href="/clientes/detalhe.php?id=<?= $c['id_cliente'] ?>" class="btn-sm btn-outline">ver</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
</main>
</body>
</html>
