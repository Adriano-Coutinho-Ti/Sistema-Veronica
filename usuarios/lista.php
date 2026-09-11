<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$usuarios = $pdo->query('SELECT id_usuario, nome, email, perfil, ativo FROM usuarios ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Usuários</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Usuários</h1>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Usuário criado com sucesso.</p><?php endif; ?>
    <p><a href="/usuarios/novo.php">+ Novo usuário</a></p>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th></tr>
        <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['nome']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['perfil']) ?></td>
            <td><?= $u['ativo'] ? 'Sim' : 'Não' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</main>
</body>
</html>
