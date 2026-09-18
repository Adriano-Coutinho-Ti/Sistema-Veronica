<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';
require_once __DIR__ . '/../includes/config_dev.php';
require_once __DIR__ . '/../includes/email_smtp.php';
require_once __DIR__ . '/../includes/dev_seguranca.php';

// Senha de instalação — trava quem consegue ver o formulário de configuração
// do banco. Não é uma senha secreta forte (é o mesmo "DevMaster" usado como
// usuário do painel), só evita que qualquer visitante que caia nesta tela
// durante uma falha real de conexão em produção já veja o formulário.
const SENHA_INSTALACAO_MD5 = 'be1e875d04b3c459e88990acfa7eb5ad'; // md5('DevMaster')

// Botão "Editar" em painel_dev/index.php manda pra cá com ?editar_banco=1
// pra reabrir o mesmo assistente mesmo com o banco já conectado. Fica preso
// num campo escondido nos formulários (não numa flag de sessão) pra sumir
// sozinho assim que o navegador parar de mandar o parâmetro — sem precisar
// desmarcar nada manualmente em nenhum passo do fluxo.
$modoEdicaoBanco = isset($_GET['editar_banco']) || ($_POST['editar_banco'] ?? '') === '1';

// Um clique novo em "Editar" (sempre chega como GET, nunca como parte de um
// POST em andamento) exige a senha de instalação de novo, mesmo que ela já
// tenha sido confirmada antes nesta mesma sessão — é justamente a proteção
// extra pra essa tela, não pode virar "só pede uma vez por sessão".
if (isset($_GET['editar_banco'])) {
    unset($_SESSION['assistente_banco_autorizado']);
}

$mensagemConexao = '';
$tipoMensagemConexao = '';
$erroSenhaInstalacao = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'verificar_senha_instalacao') {
    if (md5($_POST['senha_instalacao'] ?? '') === SENHA_INSTALACAO_MD5) {
        $_SESSION['assistente_banco_autorizado'] = true;
    } else {
        $erroSenhaInstalacao = 'Senha de instalação incorreta.';
    }
}

// Assistente de configuração do banco — só entra em jogo quando o banco
// ainda não está acessível (config_credenciais.php não existe, ou existe mas
// a conexão falhou: senha errada, banco fora do ar, etc) E a senha de
// instalação já foi confirmada nesta sessão. Testa a conexão ANTES de
// gravar qualquer coisa, pra nunca salvar um dado que não funciona.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'configurar_banco' && !empty($_SESSION['assistente_banco_autorizado'])) {
    $hostForm = trim($_POST['db_host'] ?? '');
    $dbnameForm = trim($_POST['db_dbname'] ?? '');
    $usernameForm = trim($_POST['db_username'] ?? '');
    $passwordForm = $_POST['db_password'] ?? '';

    try {
        new PDO(
            "mysql:host=$hostForm;dbname=$dbnameForm;charset=utf8mb4",
            $usernameForm,
            $passwordForm,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );

        $conteudoArquivo = "<?php\n"
            . "// Gerado pelo assistente de configuração em /painel_dev/login.php — não versionado (.gitignore).\n"
            . '$host = ' . var_export($hostForm, true) . ";\n"
            . '$dbname = ' . var_export($dbnameForm, true) . ";\n"
            . '$username = ' . var_export($usernameForm, true) . ";\n"
            . '$password = ' . var_export($passwordForm, true) . ";\n";

        $gravou = @file_put_contents(__DIR__ . '/../config_credenciais.php', $conteudoArquivo, LOCK_EX);

        if ($gravou === false) {
            $mensagemConexao = 'Conectou, mas não consegui gravar o arquivo config_credenciais.php. Confira a permissão de escrita na pasta do sistema e tente de novo.';
            $tipoMensagemConexao = 'erro';
        } else {
            $mensagemConexao = 'Conectado ao banco de dados com sucesso!';
            $tipoMensagemConexao = 'sucesso';
            // Não desmarca $_SESSION['assistente_banco_autorizado'] aqui: isso
            // faria esta MESMA resposta (que ainda precisa mostrar o alerta de
            // sucesso por 3s antes de redirecionar) cair de volta na tela de
            // senha de instalação. Uma vez conectado, $bancoDisponivel passa a
            // ser true no próximo carregamento e essa flag nunca mais é lida.
        }
    } catch (Throwable $e) {
        error_log('painel_dev/login.php: falha ao testar conexão informada no assistente: ' . $e->getMessage());
        $mensagemConexao = 'Não foi possível conectar ao banco de dados. Confira os dados e tente novamente.';
        $tipoMensagemConexao = 'erro';
    }
}

$bancoDisponivel = BANCO_CONFIGURADO && isset($pdo) && $pdo instanceof PDO;
$mostrarAssistente = !$bancoDisponivel || $modoEdicaoBanco;

$erro = '';

if ($bancoDisponivel && !$modoEdicaoBanco) {
    if (!empty($_SESSION['dev_usuario_id'])) {
        header('Location: /painel_dev/index.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === '') {
        $usuario = trim($_POST['usuario'] ?? '');
        $senha = $_POST['senha'] ?? '';

        $dev = buscarDevParaLogin($pdo, $usuario);
        $bloqueio = $dev ? mensagemBloqueioLogin($dev) : null;

        if ($dev && $bloqueio) {
            $erro = $bloqueio;
        } elseif ($dev && password_verify($senha, $dev['senha_hash'])) {
            limparTentativasLogin($pdo, (int) $dev['id_dev_usuario']);
            $_SESSION['dev_usuario_id'] = (int) $dev['id_dev_usuario'];
            $_SESSION['dev_usuario_nome'] = $dev['nome'] ?: $usuario;
            $_SESSION['dev_modo_recuperacao'] = false;
            session_regenerate_id(true);
            header('Location: ' . destinoAposLoginDev($dev));
            exit;
        } elseif ($dev && !empty($dev['senha_recuperacao_hash']) && password_verify($senha, $dev['senha_recuperacao_hash'])) {
            limparTentativasLogin($pdo, (int) $dev['id_dev_usuario']);
            $_SESSION['dev_usuario_id'] = (int) $dev['id_dev_usuario'];
            $_SESSION['dev_usuario_nome'] = $dev['nome'] ?: $usuario;
            $_SESSION['dev_modo_recuperacao'] = true;
            session_regenerate_id(true);
            header('Location: ' . destinoAposLoginDev($dev));
            exit;
        } else {
            if ($dev) {
                registrarTentativaFalha($pdo, $dev);
            }
            $erro = 'Usuário ou senha incorretos.';
        }
    }
}

// Prefill do assistente: prioriza o que acabou de ser digitado (pra não
// perder o que a pessoa já tinha preenchido se um teste falhar); na falta
// disso, usa o que já está no arquivo de credenciais (útil quando o arquivo
// existe mas a senha está errada — só esse campo fica em branco). Senha
// nunca é preenchida de volta, por segurança.
$valorHost = $_POST['db_host'] ?? ($host ?? '');
$valorDbname = $_POST['db_dbname'] ?? ($dbname ?? '');
$valorUsername = $_POST['db_username'] ?? ($username ?? '');

$versaoCssAdmin = @filemtime(__DIR__ . '/../assets/css/admin.css') ?: time();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Painel do desenvolvedor</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Manrope:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="/assets/css/admin.css?v=<?= $versaoCssAdmin ?>">
</head>
<body style="background:#0F172A;">
<main class="container">
    <?php if ($mostrarAssistente && empty($_SESSION['assistente_banco_autorizado'])): ?>

        <div class="auth-card">
            <p style="text-align:center; text-transform:uppercase; letter-spacing:0.08em; font-size:0.75rem; font-weight:700; color:var(--cor-primaria); margin-bottom:6px;">Configuração <?= $modoEdicaoBanco ? 'do banco' : 'inicial' ?></p>
            <h1>Senha de instalação</h1>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">
                <?= $modoEdicaoBanco
                    ? 'Confirme a senha de instalação pra editar os dados de conexão com o banco.'
                    : 'O sistema ainda não está conectado a um banco de dados. Confirme a senha de instalação pra abrir o assistente de configuração.' ?>
            </p>
            <?php if ($erroSenhaInstalacao): ?>
                <p class="alert alert-erro"><?= htmlspecialchars($erroSenhaInstalacao) ?></p>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="acao" value="verificar_senha_instalacao">
                <?php if ($modoEdicaoBanco): ?><input type="hidden" name="editar_banco" value="1"><?php endif; ?>
                <label>Senha de instalação<input type="password" name="senha_instalacao" required autofocus></label>
                <button type="submit" class="btn-bloco">Continuar</button>
            </form>
            <?php if ($modoEdicaoBanco): ?><p style="text-align:center; margin-top:14px;"><a href="/painel_dev/index.php">Cancelar</a></p><?php endif; ?>
        </div>

    <?php elseif ($mostrarAssistente): ?>

        <?php if ($tipoMensagemConexao): ?>
        <div class="auth-card" id="alerta-conexao">
            <p class="alert alert-<?= $tipoMensagemConexao ?>" style="margin:0;"><?= htmlspecialchars($mensagemConexao) ?></p>
        </div>
        <?php endif; ?>

        <div class="auth-card" id="form-configurar-banco" <?= $tipoMensagemConexao ? 'hidden' : '' ?>>
            <p style="text-align:center; text-transform:uppercase; letter-spacing:0.08em; font-size:0.75rem; font-weight:700; color:var(--cor-primaria); margin-bottom:6px;">Configuração <?= $modoEdicaoBanco ? 'do banco' : 'inicial' ?></p>
            <h1>Conectar ao banco de dados</h1>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">
                <?= $modoEdicaoBanco
                    ? 'Os campos abaixo já vêm preenchidos com a conexão atual — troque só o que for preciso.'
                    : 'Preencha os dados de acesso abaixo — depois de conectar, o resto da configuração (Mercado Pago, e-mail, nome do sistema) fica disponível aqui mesmo no painel.' ?>
            </p>
            <form method="post">
                <input type="hidden" name="acao" value="configurar_banco">
                <?php if ($modoEdicaoBanco): ?><input type="hidden" name="editar_banco" value="1"><?php endif; ?>
                <label>Host<input type="text" name="db_host" value="<?= htmlspecialchars($valorHost) ?>" required autofocus></label>
                <label>Nome do banco<input type="text" name="db_dbname" value="<?= htmlspecialchars($valorDbname) ?>" required></label>
                <label>Usuário<input type="text" name="db_username" value="<?= htmlspecialchars($valorUsername) ?>" required></label>
                <label>Senha<input type="password" name="db_password" required></label>
                <button type="submit" class="btn-bloco">Conectar e salvar</button>
            </form>
            <?php if ($modoEdicaoBanco): ?><p style="text-align:center; margin-top:14px;"><a href="/painel_dev/index.php">Cancelar</a></p><?php endif; ?>
        </div>

        <?php if ($tipoMensagemConexao): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(function () {
                <?php if ($tipoMensagemConexao === 'sucesso'): ?>
                window.location.href = '/painel_dev/login.php';
                <?php else: ?>
                document.getElementById('alerta-conexao').hidden = true;
                document.getElementById('form-configurar-banco').hidden = false;
                <?php endif; ?>
            }, 3000);
        });
        </script>
        <?php endif; ?>

    <?php else: ?>

        <div class="auth-card">
            <p style="text-align:center; text-transform:uppercase; letter-spacing:0.08em; font-size:0.75rem; font-weight:700; color:var(--cor-primaria); margin-bottom:6px;">Área restrita</p>
            <h1>Painel do desenvolvedor</h1>
            <?php if ($erro): ?>
                <p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p>
            <?php endif; ?>
            <form method="post">
                <label>Usuário<input type="text" name="usuario" autocomplete="off" required autofocus></label>
                <label>Senha<input type="password" name="senha" required></label>
                <button type="submit" class="btn-bloco">Entrar</button>
            </form>
        </div>

    <?php endif; ?>
</main>
</body>
</html>
