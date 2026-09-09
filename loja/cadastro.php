<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';

if (!empty($_SESSION['id_cliente'])) {
    header('Location: /loja/index.php');
    exit;
}

$erro = '';
$etapa = 'whatsapp';
$whatsappNormalizado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'verificar_whatsapp') {
        $whatsappNormalizado = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
        if (strlen($whatsappNormalizado) === 10 || strlen($whatsappNormalizado) === 11) {
            $whatsappNormalizado = '55' . $whatsappNormalizado;
        }

        if (strlen($whatsappNormalizado) < 12) {
            $erro = 'Informe um WhatsApp válido (com DDD).';
            $etapa = 'whatsapp';
        } else {
            $stmt = $pdo->prepare('SELECT id_cliente, senha_hash FROM clientes WHERE whatsapp = :w');
            $stmt->execute([':w' => $whatsappNormalizado]);
            $clienteExistente = $stmt->fetch();

            if (!$clienteExistente) {
                $etapa = 'cadastro';
            } elseif (empty($clienteExistente['senha_hash'])) {
                $etapa = 'ativar';
            } else {
                $etapa = 'login';
            }
        }
    } elseif ($acao === 'cadastro') {
        $whatsappNormalizado = $_POST['whatsapp'] ?? '';
        $nome = trim($_POST['nome'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if ($nome === '' || strlen($senha) < 6) {
            $erro = 'Informe seu nome e uma senha com pelo menos 6 caracteres.';
            $etapa = 'cadastro';
        } else {
            try {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO clientes (nome, whatsapp, senha_hash) VALUES (:nome, :whatsapp, :senha)');
                $stmt->execute([':nome' => $nome, ':whatsapp' => $whatsappNormalizado, ':senha' => $hash]);
                $_SESSION['id_cliente'] = (int) $pdo->lastInsertId();
                $_SESSION['nome_cliente'] = $nome;
                header('Location: /loja/index.php');
                exit;
            } catch (PDOException $e) {
                $erro = 'Esse WhatsApp já tem cadastro. Tente entrar em vez de se cadastrar.';
                $etapa = 'whatsapp';
            }
        }
    } elseif ($acao === 'ativar') {
        $whatsappNormalizado = $_POST['whatsapp'] ?? '';
        $senha = $_POST['senha'] ?? '';

        if (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
            $etapa = 'ativar';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE clientes SET senha_hash = :senha WHERE whatsapp = :whatsapp')
                ->execute([':senha' => $hash, ':whatsapp' => $whatsappNormalizado]);

            $stmtC = $pdo->prepare('SELECT id_cliente, nome FROM clientes WHERE whatsapp = :whatsapp');
            $stmtC->execute([':whatsapp' => $whatsappNormalizado]);
            $cliente = $stmtC->fetch();

            $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
            $_SESSION['nome_cliente'] = $cliente['nome'];
            header('Location: /loja/index.php');
            exit;
        }
    } elseif ($acao === 'login') {
        $whatsappNormalizado = $_POST['whatsapp'] ?? '';
        $senha = $_POST['senha'] ?? '';

        $stmt = $pdo->prepare('SELECT id_cliente, nome, senha_hash FROM clientes WHERE whatsapp = :whatsapp');
        $stmt->execute([':whatsapp' => $whatsappNormalizado]);
        $cliente = $stmt->fetch();

        if ($cliente && password_verify($senha, $cliente['senha_hash'])) {
            $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
            $_SESSION['nome_cliente'] = $cliente['nome'];
            header('Location: /loja/index.php');
            exit;
        }

        $erro = 'WhatsApp ou senha inválidos.';
        $etapa = 'login';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Entrar</title></head>
<body>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <?php if ($etapa === 'whatsapp'): ?>
    <form method="post">
        <input type="hidden" name="acao" value="verificar_whatsapp">
        <label>WhatsApp (com DDD)<br><input type="text" name="whatsapp" required placeholder="11987654321"></label><br>
        <button type="submit">Continuar</button>
    </form>
    <?php elseif ($etapa === 'cadastro'): ?>
    <h2>Complete seu cadastro</h2>
    <form method="post">
        <input type="hidden" name="acao" value="cadastro">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Nome<br><input type="text" name="nome" required></label><br>
        <label>Crie uma senha<br><input type="password" name="senha" required minlength="6"></label><br>
        <button type="submit">Cadastrar</button>
    </form>
    <?php elseif ($etapa === 'ativar'): ?>
    <h2>Ativar minha conta</h2>
    <p>Encontramos seu cadastro. Crie uma senha pra acessar a loja online.</p>
    <form method="post">
        <input type="hidden" name="acao" value="ativar">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Crie uma senha<br><input type="password" name="senha" required minlength="6"></label><br>
        <button type="submit">Ativar</button>
    </form>
    <?php elseif ($etapa === 'login'): ?>
    <h2>Entrar</h2>
    <form method="post">
        <input type="hidden" name="acao" value="login">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Senha<br><input type="password" name="senha" required></label><br>
        <button type="submit">Entrar</button>
    </form>
    <?php endif; ?>
</body>
</html>
