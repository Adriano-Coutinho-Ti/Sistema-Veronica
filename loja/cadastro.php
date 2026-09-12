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
        // O whatsapp chega de um campo hidden que esta própria página renderizou já
        // normalizado, mas um POST direto (sem passar por verificar_whatsapp) poderia trazer
        // outro formato e criar um cliente duplicado. Normaliza de novo, igual ao
        // verificar_whatsapp e ao clientes/novo.php.
        $whatsappNormalizado = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
        if (strlen($whatsappNormalizado) === 10 || strlen($whatsappNormalizado) === 11) {
            $whatsappNormalizado = '55' . $whatsappNormalizado;
        }

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
                session_regenerate_id(true);
                header('Location: /loja/index.php');
                exit;
            } catch (PDOException $e) {
                // Check if error is specifically duplicate-key (MySQL 1062 / SQLSTATE 23000)
                if ($e->errorInfo[1] === 1062 || $e->getCode() === '23000') {
                    $erro = 'Esse WhatsApp já tem cadastro. Tente entrar em vez de se cadastrar.';
                } else {
                    error_log('PDOException in cadastro: ' . $e->getMessage());
                    $erro = 'Erro ao cadastrar. Tente novamente.';
                }
                $etapa = 'whatsapp';
            }
        }
    } elseif ($acao === 'ativar') {
        $whatsappNormalizado = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
        if (strlen($whatsappNormalizado) === 10 || strlen($whatsappNormalizado) === 11) {
            $whatsappNormalizado = '55' . $whatsappNormalizado;
        }

        $senha = $_POST['senha'] ?? '';

        if (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
            $etapa = 'ativar';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE clientes SET senha_hash = :senha WHERE whatsapp = :whatsapp AND senha_hash IS NULL');
            $stmt->execute([':senha' => $hash, ':whatsapp' => $whatsappNormalizado]);

            // Only proceed if the update actually affected a row (passwordless account)
            if ($stmt->rowCount() > 0) {
                $stmtC = $pdo->prepare('SELECT id_cliente, nome FROM clientes WHERE whatsapp = :whatsapp');
                $stmtC->execute([':whatsapp' => $whatsappNormalizado]);
                $cliente = $stmtC->fetch();

                $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
                $_SESSION['nome_cliente'] = $cliente['nome'];
                session_regenerate_id(true);
                header('Location: /loja/index.php');
                exit;
            } else {
                // Account either doesn't exist or already has a password
                $erro = 'Não foi possível ativar a conta. Verifique se o WhatsApp está correto.';
                $etapa = 'whatsapp';
            }
        }
    } elseif ($acao === 'login') {
        $whatsappNormalizado = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
        if (strlen($whatsappNormalizado) === 10 || strlen($whatsappNormalizado) === 11) {
            $whatsappNormalizado = '55' . $whatsappNormalizado;
        }

        $senha = $_POST['senha'] ?? '';

        $stmt = $pdo->prepare('SELECT id_cliente, nome, senha_hash FROM clientes WHERE whatsapp = :whatsapp');
        $stmt->execute([':whatsapp' => $whatsappNormalizado]);
        $cliente = $stmt->fetch();

        // senha_hash pode ser NULL (cliente criado pelo PDV em clientes/novo.php e nunca
        // ativado na loja) — password_verify() com NULL dispara deprecation/warning.
        if ($cliente && $cliente['senha_hash'] !== null && password_verify($senha, $cliente['senha_hash'])) {
            $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
            $_SESSION['nome_cliente'] = $cliente['nome'];
            session_regenerate_id(true);
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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Entrar</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="auth-card">
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <?php if ($etapa === 'whatsapp'): ?>
    <h1>Entrar na loja</h1>
    <form method="post">
        <input type="hidden" name="acao" value="verificar_whatsapp">
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp" required placeholder="11987654321"></label>
        <button type="submit" class="btn-bloco">Continuar</button>
    </form>
    <?php elseif ($etapa === 'cadastro'): ?>
    <h2>Complete seu cadastro</h2>
    <form method="post">
        <input type="hidden" name="acao" value="cadastro">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Nome<input type="text" name="nome" required></label>
        <label>Crie uma senha<input type="password" name="senha" required minlength="6"></label>
        <button type="submit" class="btn-bloco">Cadastrar</button>
    </form>
    <?php elseif ($etapa === 'ativar'): ?>
    <h2>Ativar minha conta</h2>
    <p>Encontramos seu cadastro. Crie uma senha pra acessar a loja online.</p>
    <form method="post">
        <input type="hidden" name="acao" value="ativar">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Crie uma senha<input type="password" name="senha" required minlength="6"></label>
        <button type="submit" class="btn-bloco">Ativar</button>
    </form>
    <?php elseif ($etapa === 'login'): ?>
    <h2>Entrar</h2>
    <form method="post">
        <input type="hidden" name="acao" value="login">
        <input type="hidden" name="whatsapp" value="<?= htmlspecialchars($whatsappNormalizado) ?>">
        <label>Senha<input type="password" name="senha" required></label>
        <button type="submit" class="btn-bloco">Entrar</button>
    </form>
    <?php endif; ?>
    </div>
</main>
</body>
</html>
