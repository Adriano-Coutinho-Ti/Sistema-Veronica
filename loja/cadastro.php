<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';

if (!empty($_SESSION['id_cliente'])) {
    header('Location: /loja/index.php');
    exit;
}

function normalizarWhatsapp(string $whatsapp): string
{
    $whatsapp = preg_replace('/\D/', '', $whatsapp);
    if (strlen($whatsapp) === 10 || strlen($whatsapp) === 11) {
        $whatsapp = '55' . $whatsapp;
    }
    return $whatsapp;
}

function normalizarEmail(string $email): string
{
    return mb_strtolower(trim($email));
}

$erro = '';
$etapa = 'email';
$emailNormalizado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'verificar_email') {
        $emailNormalizado = normalizarEmail($_POST['email'] ?? '');

        if (!filter_var($emailNormalizado, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Informe um e-mail válido.';
            $etapa = 'email';
        } else {
            $stmt = $pdo->prepare('SELECT id_cliente, senha_hash FROM clientes WHERE email = :e');
            $stmt->execute([':e' => $emailNormalizado]);
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
        // O e-mail chega de um campo hidden que esta própria página renderizou já
        // normalizado, mas um POST direto (sem passar por verificar_email) poderia trazer
        // outro formato e escapar da checagem de duplicidade. Normaliza de novo, igual ao
        // verificar_email.
        $emailNormalizado = normalizarEmail($_POST['email'] ?? '');
        $whatsappNormalizado = normalizarWhatsapp($_POST['whatsapp'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (!filter_var($emailNormalizado, FILTER_VALIDATE_EMAIL)) {
            $erro = 'E-mail inválido. Volte e informe um e-mail válido.';
            $etapa = 'email';
        } elseif ($nome === '' || strlen($whatsappNormalizado) < 12 || strlen($senha) < 6) {
            $erro = 'Informe seu nome, um WhatsApp válido (com DDD) e uma senha com pelo menos 6 caracteres.';
            $etapa = 'cadastro';
        } else {
            // Checagem prévia (em vez de só confiar na constraint UNIQUE do banco) pra
            // poder avisar exatamente qual dos dois campos já está em uso — email e
            // whatsapp são únicos e independentes.
            $stmtEmail = $pdo->prepare('SELECT id_cliente FROM clientes WHERE email = :e');
            $stmtEmail->execute([':e' => $emailNormalizado]);
            $stmtWhats = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :w');
            $stmtWhats->execute([':w' => $whatsappNormalizado]);

            if ($stmtEmail->fetch()) {
                $erro = 'Esse e-mail já tem cadastro. Tente entrar em vez de se cadastrar.';
                $etapa = 'email';
            } elseif ($stmtWhats->fetch()) {
                $erro = 'Esse WhatsApp já tem um cadastro em nosso sistema, mas sem esse e-mail vinculado. Fale com a loja pra adicionarmos seu e-mail e você conseguir entrar.';
                $etapa = 'email';
            } else {
                try {
                    $hash = password_hash($senha, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare('INSERT INTO clientes (nome, whatsapp, email, senha_hash) VALUES (:nome, :whatsapp, :email, :senha)');
                    $stmt->execute([':nome' => $nome, ':whatsapp' => $whatsappNormalizado, ':email' => $emailNormalizado, ':senha' => $hash]);
                    $_SESSION['id_cliente'] = (int) $pdo->lastInsertId();
                    $_SESSION['nome_cliente'] = $nome;
                    session_regenerate_id(true);
                    header('Location: /loja/index.php');
                    exit;
                } catch (PDOException $e) {
                    // Corrida rara (duas abas cadastrando ao mesmo tempo) — a checagem prévia
                    // acima cobre o caso comum, isso aqui é só a rede de segurança final.
                    error_log('PDOException in cadastro: ' . $e->getMessage());
                    $erro = 'Erro ao cadastrar. Tente novamente.';
                    $etapa = 'email';
                }
            }
        }
    } elseif ($acao === 'ativar') {
        $emailNormalizado = normalizarEmail($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        if (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
            $etapa = 'ativar';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE clientes SET senha_hash = :senha WHERE email = :email AND senha_hash IS NULL');
            $stmt->execute([':senha' => $hash, ':email' => $emailNormalizado]);

            // Only proceed if the update actually affected a row (passwordless account)
            if ($stmt->rowCount() > 0) {
                $stmtC = $pdo->prepare('SELECT id_cliente, nome FROM clientes WHERE email = :email');
                $stmtC->execute([':email' => $emailNormalizado]);
                $cliente = $stmtC->fetch();

                $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
                $_SESSION['nome_cliente'] = $cliente['nome'];
                session_regenerate_id(true);
                header('Location: /loja/index.php');
                exit;
            } else {
                // Account either doesn't exist or already has a password
                $erro = 'Não foi possível ativar a conta. Verifique se o e-mail está correto.';
                $etapa = 'email';
            }
        }
    } elseif ($acao === 'login') {
        $emailNormalizado = normalizarEmail($_POST['email'] ?? '');
        $senha = $_POST['senha'] ?? '';

        $stmt = $pdo->prepare('SELECT id_cliente, nome, senha_hash FROM clientes WHERE email = :email');
        $stmt->execute([':email' => $emailNormalizado]);
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

        $erro = 'E-mail ou senha inválidos.';
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

    <?php if ($etapa === 'email'): ?>
    <h1>Entrar na loja</h1>
    <form method="post">
        <input type="hidden" name="acao" value="verificar_email">
        <label>E-mail<input type="email" name="email" required placeholder="voce@email.com" value="<?= htmlspecialchars($emailNormalizado) ?>"></label>
        <button type="submit" class="btn-bloco">Continuar</button>
    </form>
    <?php elseif ($etapa === 'cadastro'): ?>
    <h2>Complete seu cadastro</h2>
    <form method="post">
        <input type="hidden" name="acao" value="cadastro">
        <input type="hidden" name="email" value="<?= htmlspecialchars($emailNormalizado) ?>">
        <label>Nome<input type="text" name="nome" required></label>
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp" required placeholder="11987654321"></label>
        <label>Crie uma senha<input type="password" name="senha" required minlength="6"></label>
        <button type="submit" class="btn-bloco">Cadastrar</button>
    </form>
    <?php elseif ($etapa === 'ativar'): ?>
    <h2>Ativar minha conta</h2>
    <p>Encontramos seu cadastro. Crie uma senha pra acessar a loja online.</p>
    <form method="post">
        <input type="hidden" name="acao" value="ativar">
        <input type="hidden" name="email" value="<?= htmlspecialchars($emailNormalizado) ?>">
        <label>Crie uma senha<input type="password" name="senha" required minlength="6"></label>
        <button type="submit" class="btn-bloco">Ativar</button>
    </form>
    <?php elseif ($etapa === 'login'): ?>
    <h2>Entrar</h2>
    <form method="post">
        <input type="hidden" name="acao" value="login">
        <input type="hidden" name="email" value="<?= htmlspecialchars($emailNormalizado) ?>">
        <label>Senha<input type="password" name="senha" required></label>
        <button type="submit" class="btn-bloco">Entrar</button>
    </form>
    <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
