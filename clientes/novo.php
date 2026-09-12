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
    $email = trim($_POST['email'] ?? '');
    $email = $email !== '' ? mb_strtolower($email) : null;
    $endereco = trim($_POST['endereco'] ?? '') ?: null;

    if ($nome === '' || strlen($whatsapp) < 10) {
        $erro = 'Informe nome e um WhatsApp válido (com DDD).';
    } elseif ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erro = 'Informe um e-mail válido (ou deixe em branco).';
    } else {
        $existe = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :whatsapp');
        $existe->execute([':whatsapp' => $whatsapp]);
        $existeEmail = $email !== null ? $pdo->prepare('SELECT id_cliente FROM clientes WHERE email = :email') : null;
        if ($existeEmail) {
            $existeEmail->execute([':email' => $email]);
        }
        if ($existe->fetch()) {
            $erro = 'Já existe um cliente cadastrado com esse WhatsApp.';
        } elseif ($existeEmail && $existeEmail->fetch()) {
            $erro = 'Já existe um cliente cadastrado com esse e-mail.';
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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Novo cliente</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Novo cliente</h1>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <div class="card">
    <form method="post">
        <label>Nome<input type="text" name="nome" required></label>
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp" required placeholder="11987654321"></label>
        <label>E-mail<input type="email" name="email"></label>
        <label>Endereço<input type="text" name="endereco"></label>
        <button type="submit">Salvar</button>
    </form>
    </div>
    <p><a href="/clientes/lista.php" class="btn-texto">← Ver clientes</a></p>
</main>
</body>
</html>
