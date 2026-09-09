<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $whatsapp = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    if (strlen($whatsapp) === 10 || strlen($whatsapp) === 11) {
        $whatsapp = '55' . $whatsapp;
    }
    $email = trim($_POST['email'] ?? '') ?: null;
    $endereco = trim($_POST['endereco'] ?? '') ?: null;

    if ($nome === '' || strlen($whatsapp) < 10) {
        $erro = 'Informe nome e um WhatsApp válido (com DDD).';
    } else {
        $existe = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :whatsapp');
        $existe->execute([':whatsapp' => $whatsapp]);
        if ($existe->fetch()) {
            $erro = 'Já existe um cliente cadastrado com esse WhatsApp.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO clientes (nome, whatsapp, email, endereco) VALUES (:nome, :whatsapp, :email, :endereco)');
            $stmt->execute([':nome' => $nome, ':whatsapp' => $whatsapp, ':email' => $email, ':endereco' => $endereco]);
            header('Location: /clientes/lista.php?criado=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Novo cliente</title></head>
<body>
    <h1>Novo cliente</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <label>Nome<br><input type="text" name="nome" required></label><br>
        <label>WhatsApp (com DDD)<br><input type="text" name="whatsapp" required placeholder="11987654321"></label><br>
        <label>E-mail<br><input type="email" name="email"></label><br>
        <label>Endereço<br><input type="text" name="endereco"></label><br>
        <button type="submit">Salvar</button>
    </form>
    <p><a href="/clientes/lista.php">Ver clientes</a></p>
</body>
</html>
