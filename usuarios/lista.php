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
    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Usuário criado com sucesso.</p><?php endif; ?>
    <p><a href="/usuarios/novo.php" class="btn">+ Novo usuário</a></p>
    <div class="tabela-wrap">
    <table>
        <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th></tr>
        <?php foreach ($usuarios as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['nome']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="status-pill<?= $u['perfil'] === 'Admin' ? ' sucesso' : '' ?>"><?= htmlspecialchars($u['perfil']) ?></span></td>
            <td><?= $u['ativo'] ? 'Sim' : 'Não' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
</main>
</body>
</html>
