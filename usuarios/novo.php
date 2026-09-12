<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $perfil = $_POST['perfil'] === 'Admin' ? 'Admin' : 'Funcionario';

    if ($nome === '' || $email === '' || strlen($senha) < 6) {
        $erro = 'Preencha nome, e-mail e uma senha com pelo menos 6 caracteres.';
    } else {
        $existe = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = :email');
        $existe->execute([':email' => $email]);
        if ($existe->fetch()) {
            $erro = 'Já existe um usuário com esse e-mail.';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (:nome, :email, :hash, :perfil)');
            $stmt->execute([':nome' => $nome, ':email' => $email, ':hash' => $hash, ':perfil' => $perfil]);
            header('Location: /usuarios/lista.php?criado=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Novo usuário</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Novo usuário</h1>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <div class="card">
    <form method="post">
        <label>Nome<input type="text" name="nome" required></label>
        <label>E-mail<input type="email" name="email" required></label>
        <label>Senha<input type="password" name="senha" required minlength="6"></label>
        <label>Perfil
            <select name="perfil">
                <option value="Funcionario">Funcionário</option>
                <option value="Admin">Admin</option>
            </select>
        </label>
        <button type="submit">Salvar</button>
    </form>
    </div>
    <p><a href="/usuarios/lista.php" class="btn-texto">← Ver usuários</a></p>
</main>
</body>
</html>
