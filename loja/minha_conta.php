<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];
$erroPerfil = '';
$erroSenha = '';
$sucessoPerfil = false;
$sucessoSenha = false;
$avisoEmailMudou = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'perfil') {
    $nome = trim($_POST['nome'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $whatsappNormalizado = preg_replace('/\D/', '', $_POST['whatsapp'] ?? '');
    if (strlen($whatsappNormalizado) === 10 || strlen($whatsappNormalizado) === 11) {
        $whatsappNormalizado = '55' . $whatsappNormalizado;
    }
    $endereco = trim($_POST['endereco'] ?? '');

    if ($nome === '') {
        $erroPerfil = 'Informe seu nome.';
    } elseif ($email === '') {
        // E-mail é o login do cliente — não dá pra deixar em branco depois de já ter
        // uma conta ativa (diferente do cadastro pelo PDV, que ainda aceita sem e-mail).
        $erroPerfil = 'Informe seu e-mail — ele é usado pra entrar na loja.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erroPerfil = 'Informe um e-mail válido.';
    } elseif (strlen($whatsappNormalizado) < 12) {
        $erroPerfil = 'Informe um WhatsApp válido, com DDD.';
    } else {
        $stmtDupEmail = $pdo->prepare('SELECT id_cliente FROM clientes WHERE email = :email AND id_cliente != :id');
        $stmtDupEmail->execute([':email' => $email, ':id' => $id_cliente]);
        $stmtDupWhats = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :w AND id_cliente != :id');
        $stmtDupWhats->execute([':w' => $whatsappNormalizado, ':id' => $id_cliente]);

        if ($stmtDupEmail->fetch()) {
            $erroPerfil = 'Esse e-mail já está sendo usado por outra conta.';
        } elseif ($stmtDupWhats->fetch()) {
            $erroPerfil = 'Esse WhatsApp já está sendo usado por outra conta.';
        } else {
            $emailAtual = $pdo->prepare('SELECT email FROM clientes WHERE id_cliente = :id');
            $emailAtual->execute([':id' => $id_cliente]);
            $emailMudou = $emailAtual->fetchColumn() !== $email;

            if ($emailMudou) {
                // Novo e-mail é um dado não confirmado até o cliente clicar no link — mesma
                // regra do cadastro. Trava o carrinho de novo até essa confirmação.
                $pdo->prepare('UPDATE clientes SET nome = :nome, whatsapp = :whatsapp, email = :email, endereco = :endereco, email_verificado_em = NULL WHERE id_cliente = :id')
                    ->execute([':nome' => $nome, ':whatsapp' => $whatsappNormalizado, ':email' => $email, ':endereco' => $endereco ?: null, ':id' => $id_cliente]);
                dispararVerificacaoEmail($pdo, $id_cliente, $email, $nome);
                $avisoEmailMudou = true;
            } else {
                $pdo->prepare('UPDATE clientes SET nome = :nome, whatsapp = :whatsapp, email = :email, endereco = :endereco WHERE id_cliente = :id')
                    ->execute([':nome' => $nome, ':whatsapp' => $whatsappNormalizado, ':email' => $email, ':endereco' => $endereco ?: null, ':id' => $id_cliente]);
            }
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

$stmt = $pdo->prepare('SELECT nome, whatsapp, email, endereco, email_verificado_em FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();
$emailVerificado = $cliente['email_verificado_em'] !== null;
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
                <?php if ($sucessoPerfil && $avisoEmailMudou): ?><p class="alert alert-sucesso">Dados atualizados. Enviamos um código de confirmação pro seu novo e-mail — o carrinho fica bloqueado até você validar.</p><?php endif; ?>
                <?php if ($sucessoPerfil && !$avisoEmailMudou): ?><p class="alert alert-sucesso">Dados atualizados.</p><?php endif; ?>
                <?php if ($erroPerfil): ?><p class="alert alert-erro"><?= htmlspecialchars($erroPerfil) ?></p><?php endif; ?>
                <form method="post">
                    <input type="hidden" name="acao" value="perfil">
                    <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($cliente['nome']) ?>" required></label>
                    <label>WhatsApp<input type="text" name="whatsapp" id="campo-whatsapp" value="<?= htmlspecialchars(formatarWhatsappParaEdicao($cliente['whatsapp'])) ?>" required inputmode="numeric" maxlength="16"></label>
                    <label><?= $emailVerificado ? 'E-mail (Validado)' : 'E-mail (Aguardando validação)' ?><input type="email" name="email" id="campo-email" value="<?= htmlspecialchars($cliente['email'] ?? '') ?>" required></label>
                    <?php if (!$emailVerificado): ?>
                    <p style="margin-top:-8px; margin-bottom:14px;">
                        <button type="button" class="btn-texto btn-abrir-validar-email" style="padding:0;">Validar e-mail</button>
                    </p>
                    <?php endif; ?>
                    <label>Endereço<textarea name="endereco"><?= htmlspecialchars($cliente['endereco'] ?? '') ?></textarea></label>
                    <p style="margin-top:-8px; color:var(--cor-texto-suave); font-size:0.85rem;">O endereço é opcional, mas importante se precisarmos entregar alguma compra sua.</p>
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
                    <label>Nova senha<input type="password" name="nova_senha" id="campo-nova-senha" required minlength="6"></label>
                    <label>Confirmar nova senha<input type="password" name="confirmar_senha" required minlength="6"></label>
                    <button type="submit" class="btn-outline btn-bloco">Trocar senha</button>
                </form>
            </div>
        </div>
    </div>
    <script>
    // Roda depois do DOMContentLoaded porque esse script inline (sem defer)
    // executa antes do loja.js (que tem defer) — sem esperar, as funções
    // ativarMascaraTelefone()/ativarFeedbackSenha() ainda não existiriam.
    document.addEventListener('DOMContentLoaded', function () {
        ativarMascaraTelefone(document.getElementById('campo-whatsapp'));
        ativarFeedbackSenha(document.getElementById('campo-nova-senha'));
    });
    </script>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
