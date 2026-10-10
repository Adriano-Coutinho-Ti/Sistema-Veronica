<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

if (!empty($_SESSION['id_cliente'])) {
    header('Location: /loja/index.php');
    exit;
}

function normalizarEmail(string $email): string
{
    return mb_strtolower(trim($email));
}

// Só primeiro nome não identifica ninguém direito pra loja — exige nome
// completo (pelo menos duas palavras).
function nomeCompleto(string $nome): bool
{
    return count(array_filter(preg_split('/\s+/', trim($nome)))) >= 2;
}

$whatsappLoginHabilitado = whatsappAtivo($pdo);

$erro = '';
$etapa = 'email';
$emailNormalizado = '';
$identificador = '';
$tipoIdentificador = 'email';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'verificar_email') {
        $entrada = trim($_POST['identificador'] ?? '');
        $pareceEmail = str_contains($entrada, '@');

        if ($pareceEmail) {
            $emailNormalizado = normalizarEmail($entrada);
            $identificador = $emailNormalizado;
            $tipoIdentificador = 'email';

            if (!filter_var($emailNormalizado, FILTER_VALIDATE_EMAIL)) {
                $erro = 'Informe um e-mail válido.';
                $etapa = 'email';
            } else {
                $stmt = $pdo->prepare('SELECT id_cliente, senha_hash, excluido_em FROM clientes WHERE email = :e');
                $stmt->execute([':e' => $emailNormalizado]);
                $clienteExistente = $stmt->fetch();

                if ($clienteExistente && $clienteExistente['excluido_em'] !== null) {
                    $erro = 'Esse cadastro não está disponível. Fale com a loja.';
                    $etapa = 'email';
                } elseif (!$clienteExistente) {
                    $etapa = 'cadastro';
                } elseif (empty($clienteExistente['senha_hash'])) {
                    $etapa = 'ativar';
                } else {
                    $etapa = 'login';
                }
            }
        } else {
            $whatsappNormalizado = normalizarWhatsapp($entrada);
            $identificador = $whatsappNormalizado;
            $tipoIdentificador = 'whatsapp';

            if ($whatsappNormalizado === '') {
                $erro = $whatsappLoginHabilitado
                    ? 'Informe um WhatsApp válido, com DDD e o 9 na frente (ex: (11) 90000-0000).'
                    : 'Informe um e-mail válido, ou o WhatsApp de uma conta que já existe.';
                $etapa = 'email';
            } else {
                $stmt = $pdo->prepare('SELECT id_cliente, senha_hash, excluido_em, login_whatsapp_bloqueado FROM clientes WHERE whatsapp = :w');
                $stmt->execute([':w' => $whatsappNormalizado]);
                $clienteExistente = $stmt->fetch();

                if ($clienteExistente && $clienteExistente['excluido_em'] !== null) {
                    $erro = 'Esse cadastro não está disponível. Fale com a loja.';
                    $etapa = 'email';
                } elseif ($clienteExistente && (int) $clienteExistente['login_whatsapp_bloqueado'] === 1) {
                    $erro = 'O acesso por WhatsApp foi desativado nesta conta. Entre com o seu e-mail.';
                    $etapa = 'email';
                } elseif (!$clienteExistente && !$whatsappLoginHabilitado) {
                    // Login por número de quem já tem conta continua valendo com o recurso
                    // desligado, mas cadastro novo só com número precisa dele ligado.
                    $erro = 'Esse WhatsApp não tem cadastro. Cadastre-se com seu e-mail.';
                    $etapa = 'email';
                } elseif (!$clienteExistente) {
                    // Cadastro só com o número: sem e-mail, o cliente entra e valida
                    // o WhatsApp depois (código enviado logo após o cadastro).
                    $etapa = 'cadastro';
                } elseif (empty($clienteExistente['senha_hash'])) {
                    $etapa = 'ativar';
                } else {
                    $etapa = 'login';
                }
            }
        }
    } elseif ($acao === 'cadastro' && $whatsappLoginHabilitado && ($_POST['tipo_identificador'] ?? '') === 'whatsapp') {
        // Cadastro começado pelo número de WhatsApp (sem e-mail). O número chega
        // de um campo readonly, mas um POST direto poderia trazer qualquer coisa --
        // normaliza e checa duplicidade de novo, igual ao caminho por e-mail.
        $whatsappNormalizado = normalizarWhatsapp($_POST['whatsapp'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $senha = $_POST['senha'] ?? '';
        $tipoIdentificador = 'whatsapp';
        $identificador = $whatsappNormalizado;

        if ($whatsappNormalizado === '') {
            $erro = 'Informe um WhatsApp válido, com DDD e o 9 na frente (ex: (11) 90000-0000).';
            $etapa = 'email';
        } elseif (!nomeCompleto($nome)) {
            $erro = 'Informe seu nome completo (nome e sobrenome).';
            $etapa = 'cadastro';
        } elseif (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
            $etapa = 'cadastro';
        } else {
            $stmtWhats = $pdo->prepare('SELECT id_cliente FROM clientes WHERE whatsapp = :w');
            $stmtWhats->execute([':w' => $whatsappNormalizado]);

            if ($stmtWhats->fetch()) {
                $erro = 'Esse WhatsApp já tem cadastro. Tente entrar em vez de se cadastrar.';
                $etapa = 'email';
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO clientes (nome, whatsapp, senha_hash) VALUES (:nome, :whatsapp, :senha)');
                    $stmt->execute([':nome' => $nome, ':whatsapp' => $whatsappNormalizado, ':senha' => password_hash($senha, PASSWORD_DEFAULT)]);
                    $idClienteNovo = (int) $pdo->lastInsertId();
                    $_SESSION['id_cliente'] = $idClienteNovo;
                    $_SESSION['nome_cliente'] = $nome;
                    session_regenerate_id(true);
                    // Mesmo papel da verificação de e-mail no cadastro por e-mail: o
                    // cliente já navega, só fica sem o carrinho até validar o contato.
                    dispararVerificacaoWhatsapp($pdo, $idClienteNovo, $whatsappNormalizado, $nome);
                    header('Location: /loja/index.php');
                    exit;
                } catch (PDOException $e) {
                    error_log('PDOException in cadastro (whatsapp): ' . $e->getMessage());
                    $erro = 'Erro ao cadastrar. Tente novamente.';
                    $etapa = 'email';
                }
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
        } elseif (!nomeCompleto($nome)) {
            $erro = 'Informe seu nome completo (nome e sobrenome).';
            $etapa = 'cadastro';
        } elseif ($whatsappNormalizado === '') {
            $erro = 'Informe um WhatsApp válido, com DDD e o 9 na frente (ex: (11) 90000-0000).';
            $etapa = 'cadastro';
        } elseif (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
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
                    $idClienteNovo = (int) $pdo->lastInsertId();
                    $_SESSION['id_cliente'] = $idClienteNovo;
                    $_SESSION['nome_cliente'] = $nome;
                    session_regenerate_id(true);
                    // Cliente já entra navegando normalmente — só fica sem poder usar o
                    // carrinho até confirmar que o e-mail é de verdade dele.
                    dispararVerificacaoEmail($pdo, $idClienteNovo, $emailNormalizado, $nome);
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
        $tipoIdentificador = ($_POST['tipo_identificador'] ?? '') === 'whatsapp' ? 'whatsapp' : 'email';
        $identificador = trim($_POST['identificador'] ?? '');
        $coluna = $tipoIdentificador === 'whatsapp' ? 'whatsapp' : 'email';
        $senha = $_POST['senha'] ?? '';

        if (strlen($senha) < 6) {
            $erro = 'A senha precisa ter pelo menos 6 caracteres.';
            $etapa = 'ativar';
        } else {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE clientes SET senha_hash = :senha WHERE $coluna = :id AND senha_hash IS NULL AND excluido_em IS NULL" . ($coluna === 'whatsapp' ? ' AND login_whatsapp_bloqueado = 0' : ''));
            $stmt->execute([':senha' => $hash, ':id' => $identificador]);

            // Only proceed if the update actually affected a row (passwordless account)
            if ($stmt->rowCount() > 0) {
                $stmtC = $pdo->prepare("SELECT id_cliente, nome, email FROM clientes WHERE $coluna = :id");
                $stmtC->execute([':id' => $identificador]);
                $cliente = $stmtC->fetch();

                $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
                $_SESSION['nome_cliente'] = $cliente['nome'];
                session_regenerate_id(true);
                // Esse e-mail/WhatsApp foi digitado pelo lojista no PDV, nunca confirmado
                // pelo próprio dono da conta -- mesma regra do cadastro novo. Só dispara
                // se o cadastro tiver e-mail (cliente do PDV pode não ter informado).
                if (!empty($cliente['email'])) {
                    dispararVerificacaoEmail($pdo, (int) $cliente['id_cliente'], $cliente['email'], $cliente['nome']);
                }
                header('Location: /loja/index.php');
                exit;
            } else {
                // Account either doesn't exist or already has a password
                $erro = 'Não foi possível ativar a conta. Verifique os dados.';
                $etapa = 'email';
            }
        }
    } elseif ($acao === 'login') {
        $tipoIdentificador = ($_POST['tipo_identificador'] ?? '') === 'whatsapp' ? 'whatsapp' : 'email';
        $identificador = trim($_POST['identificador'] ?? '');
        $coluna = $tipoIdentificador === 'whatsapp' ? 'whatsapp' : 'email';
        $senha = $_POST['senha'] ?? '';

        $stmt = $pdo->prepare("SELECT id_cliente, nome, senha_hash, excluido_em, login_whatsapp_bloqueado FROM clientes WHERE $coluna = :id");
        $stmt->execute([':id' => $identificador]);
        $cliente = $stmt->fetch();

        // senha_hash pode ser NULL (cliente criado pelo PDV em clientes/novo.php e nunca
        // ativado na loja) — password_verify() com NULL dispara deprecation/warning.
        if ($cliente && $cliente['excluido_em'] === null && !($tipoIdentificador === 'whatsapp' && (int) $cliente['login_whatsapp_bloqueado'] === 1) && $cliente['senha_hash'] !== null && password_verify($senha, $cliente['senha_hash'])) {
            $_SESSION['id_cliente'] = (int) $cliente['id_cliente'];
            $_SESSION['nome_cliente'] = $cliente['nome'];
            session_regenerate_id(true);
            header('Location: /loja/index.php');
            exit;
        }

        $erro = $tipoIdentificador === 'whatsapp' ? 'WhatsApp ou senha inválidos.' : 'E-mail ou senha inválidos.';
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
        <label>E-mail ou WhatsApp<input type="text" name="identificador" id="campo-identificador" required placeholder="voce@email.com ou (11) 90000-0000" value="<?= htmlspecialchars($identificador) ?>"></label>
        <button type="submit" class="btn-bloco">Continuar</button>
    </form>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        ativarMascaraIdentificadorLogin(document.getElementById('campo-identificador'));
    });
    </script>
    <?php elseif ($etapa === 'cadastro'): ?>
    <h2>Complete seu cadastro</h2>
    <p class="alert alert-erro" id="erro-cadastro" hidden></p>
    <form method="post" id="form-cadastro">
        <input type="hidden" name="acao" value="cadastro">
        <?php if ($tipoIdentificador === 'whatsapp'): ?>
        <input type="hidden" name="tipo_identificador" value="whatsapp">
        <?php else: ?>
        <input type="hidden" name="email" value="<?= htmlspecialchars($emailNormalizado) ?>">
        <?php endif; ?>
        <label>Nome completo<input type="text" name="nome" id="campo-nome" required></label>
        <label>WhatsApp (com DDD)<input type="text" name="whatsapp" id="campo-whatsapp" required placeholder="(11) 98765-4321" inputmode="numeric" maxlength="16"<?= $tipoIdentificador === 'whatsapp' ? ' readonly value="' . htmlspecialchars(formatarWhatsappParaEdicao($identificador)) . '"' : '' ?>></label>
        <?php if ($tipoIdentificador === 'whatsapp'): ?><p style="margin-top:-8px; font-size:0.85rem; color:var(--cor-texto-suave);">Esse é o número que você digitou. <a href="/loja/cadastro.php">Trocar</a></p><?php endif; ?>
        <label>Crie uma senha<input type="password" name="senha" id="campo-senha" required minlength="6"></label>
        <button type="submit" class="btn-bloco">Cadastrar</button>
    </form>
    <script>
    // Este script roda inline (sem defer) durante o parse do HTML, então
    // executa ANTES do loja.js (carregado com defer) — sem esperar
    // DOMContentLoaded, ativarMascaraTelefone()/ativarFeedbackSenha() ainda
    // não existiriam nesse ponto.
    document.addEventListener('DOMContentLoaded', function () {
        const campoWhatsapp = document.getElementById('campo-whatsapp');
        ativarMascaraTelefoneComNoveAutomatico(campoWhatsapp);

        const campoSenha = document.getElementById('campo-senha');
        ativarFeedbackSenha(campoSenha);

        function contarNomes(nome) {
            return nome.trim().split(/\s+/).filter(Boolean).length;
        }

        const erroEl = document.getElementById('erro-cadastro');
        document.getElementById('form-cadastro').addEventListener('submit', function (e) {
            const nome = document.getElementById('campo-nome').value;
            const digitosWhatsapp = campoWhatsapp.value.replace(/\D/g, '');
            const senha = campoSenha.value;
            let mensagem = '';

            if (contarNomes(nome) < 2) {
                mensagem = 'Informe seu nome completo (nome e sobrenome).';
            } else if (digitosWhatsapp.length !== 11 || digitosWhatsapp[2] !== '9') {
                mensagem = 'Informe um WhatsApp válido, com DDD e o 9 na frente (ex: (11) 90000-0000).';
            } else if (senha.length < 6) {
                mensagem = 'A senha precisa ter pelo menos 6 caracteres.';
            }

            if (mensagem) {
                e.preventDefault();
                erroEl.textContent = mensagem;
                erroEl.hidden = false;
            } else {
                erroEl.hidden = true;
            }
        });
    });
    </script>
    <?php elseif ($etapa === 'ativar'): ?>
    <h2>Ativar minha conta</h2>
    <p>Encontramos seu cadastro. Crie uma senha pra acessar a loja online.</p>
    <form method="post">
        <input type="hidden" name="acao" value="ativar">
        <input type="hidden" name="identificador" value="<?= htmlspecialchars($identificador) ?>">
        <input type="hidden" name="tipo_identificador" value="<?= htmlspecialchars($tipoIdentificador) ?>">
        <label>Crie uma senha<input type="password" name="senha" required minlength="6"></label>
        <button type="submit" class="btn-bloco">Ativar</button>
    </form>
    <?php elseif ($etapa === 'login'): ?>
    <h2>Entrar</h2>
    <form method="post">
        <input type="hidden" name="acao" value="login">
        <input type="hidden" name="identificador" value="<?= htmlspecialchars($identificador) ?>">
        <input type="hidden" name="tipo_identificador" value="<?= htmlspecialchars($tipoIdentificador) ?>">
        <label>Senha<input type="password" name="senha" required></label>
        <button type="submit" class="btn-bloco">Entrar</button>
    </form>
    <?php endif; ?>
    </div>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
