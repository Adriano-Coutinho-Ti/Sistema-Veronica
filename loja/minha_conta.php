<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];
$erroPerfil = '';
$erroSenha = '';
$sucessoPerfil = false;
$sucessoSenha = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'perfil') {
    $nome = trim($_POST['nome'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $endereco = trim($_POST['endereco'] ?? '');

    if ($nome === '') {
        $erroPerfil = 'Informe seu nome.';
    } elseif ($email === '') {
        // E-mail é o login do cliente — não dá pra deixar em branco depois de já ter
        // uma conta ativa (diferente do cadastro pelo PDV, que ainda aceita sem e-mail).
        $erroPerfil = 'Informe seu e-mail — ele é usado pra entrar na loja.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erroPerfil = 'Informe um e-mail válido.';
    } else {
        $stmtDup = $pdo->prepare('SELECT id_cliente FROM clientes WHERE email = :email AND id_cliente != :id');
        $stmtDup->execute([':email' => $email, ':id' => $id_cliente]);
        if ($stmtDup->fetch()) {
            $erroPerfil = 'Esse e-mail já está sendo usado por outra conta.';
        } else {
            $pdo->prepare('UPDATE clientes SET nome = :nome, email = :email, endereco = :endereco WHERE id_cliente = :id')
                ->execute([':nome' => $nome, ':email' => $email, ':endereco' => $endereco ?: null, ':id' => $id_cliente]);
            $_SESSION['nome_cliente'] = $nome;
            $sucessoPerfil = true;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'senha') {
    $senhaAtual = $_POST['senha_atual'] ?? '';
    $novaSenha = $_POST['nova_senha'] ?? '';
    $confirmarSenha = $_POST['confirmar_senha'] ?? '';

    $stmt = $pdo->prepare('SELECT senha_hash FROM clientes WHERE id_cliente = :id');
    $stmt->execute([':id' => $id_cliente]);
    $cliente = $stmt->fetch();

    if (empty($cliente['senha_hash']) || !password_verify($senhaAtual, $cliente['senha_hash'])) {
        $erroSenha = 'Senha atual incorreta.';
    } elseif (strlen($novaSenha) < 6) {
        $erroSenha = 'A nova senha precisa ter pelo menos 6 caracteres.';
    } elseif ($novaSenha !== $confirmarSenha) {
        $erroSenha = 'A confirmação não bate com a nova senha.';
    } else {
        $pdo->prepare('UPDATE clientes SET senha_hash = :senha WHERE id_cliente = :id')
            ->execute([':senha' => password_hash($novaSenha, PASSWORD_DEFAULT), ':id' => $id_cliente]);
        $sucessoSenha = true;
    }
}

$stmt = $pdo->prepare('SELECT nome, whatsapp, email, endereco FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Minha conta</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.24-8 5v2h16v-2c0-2.76-3.6-5-8-5Z" fill="currentColor"/></svg></span>
        <h1>Minha conta</h1>
    </div>

    <div class="layout-colunas">
        <div>
            <div class="resumo-card">
                <h2>Meus dados</h2>
                <?php if ($sucessoPerfil): ?><p class="alert alert-sucesso">Dados atualizados.</p><?php endif; ?>
                <?php if ($erroPerfil): ?><p class="alert alert-erro"><?= htmlspecialchars($erroPerfil) ?></p><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="acao" value="perfil">
                    <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required></label>
                    <label>WhatsApp (fale com a loja pra trocar)<input type="text" value="<?= htmlspecialchars($cliente['whatsapp']) ?>" disabled></label>
                    <label>E-mail (usado pra entrar)<input type="email" name="email" value="<?= htmlspecialchars($cliente['email'] ?? '') ?>" required></label>
                    <label>Endereço<textarea name="endereco"><?= htmlspecialchars($cliente['endereco'] ?? '') ?></textarea></label>
                    <button type="submit" class="btn-bloco">Salvar dados</button>
                </form>
            </div>
        </div>

        <div class="painel-lateral">
            <div class="resumo-card">
                <h2>Trocar senha</h2>
                <?php if ($sucessoSenha): ?><p class="alert alert-sucesso">Senha alterada.</p><?php endif; ?>
                <?php if ($erroSenha): ?><p class="alert alert-erro"><?= htmlspecialchars($erroSenha) ?></p><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="acao" value="senha">
                    <label>Senha atual<input type="password" name="senha_atual" required></label>
                    <label>Nova senha<input type="password" name="nova_senha" required minlength="6"></label>
                    <label>Confirmar nova senha<input type="password" name="confirmar_senha" required minlength="6"></label>
                    <button type="submit" class="btn-outline btn-bloco">Trocar senha</button>
                </form>
            </div>
        </div>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
