<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';
require_once __DIR__ . '/../includes/config_dev.php';
exigirDev();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_nome') {
    $nome = trim($_POST['nome_sistema'] ?? '');
    if ($nome === '') {
        $erro = 'Informe um nome pro sistema.';
    } else {
        $pdo->prepare('UPDATE config_dev SET nome_sistema = :nome WHERE id_config = 1')->execute([':nome' => $nome]);
        $sucesso = 'Nome do sistema atualizado.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_mercado_pago') {
    $pdo->prepare(
        'UPDATE config_dev SET mp_app_client_id = :id, mp_app_client_secret = :secret, mp_webhook_secret = :webhook WHERE id_config = 1'
    )->execute([
        ':id' => trim($_POST['mp_app_client_id'] ?? '') ?: null,
        ':secret' => trim($_POST['mp_app_client_secret'] ?? '') ?: null,
        ':webhook' => trim($_POST['mp_webhook_secret'] ?? '') ?: null,
    ]);
    $sucesso = 'Credenciais do Mercado Pago atualizadas.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_rodape') {
    $pdo->prepare('UPDATE config_dev SET footer_nome = :nome, footer_link = :link WHERE id_config = 1')->execute([
        ':nome' => trim($_POST['footer_nome'] ?? '') ?: null,
        ':link' => trim($_POST['footer_link'] ?? '') ?: null,
    ]);
    $sucesso = 'Crédito do rodapé atualizado.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_smtp') {
    $porta = trim($_POST['smtp_port'] ?? '');
    $campos = 'smtp_host = :host, smtp_port = :port, smtp_user = :user, smtp_from_email = :from_email, smtp_from_name = :from_name';
    $params = [
        ':host' => trim($_POST['smtp_host'] ?? '') ?: null,
        ':port' => $porta !== '' ? (int) $porta : null,
        ':user' => trim($_POST['smtp_user'] ?? '') ?: null,
        ':from_email' => trim($_POST['smtp_from_email'] ?? '') ?: null,
        ':from_name' => trim($_POST['smtp_from_name'] ?? '') ?: null,
    ];
    // Senha só entra na atualização se foi digitada de novo — campo em branco
    // significa "manter a que já está salva", nunca "apagar a senha".
    if (($_POST['smtp_pass'] ?? '') !== '') {
        $campos .= ', smtp_pass = :pass';
        $params[':pass'] = $_POST['smtp_pass'];
    }
    $pdo->prepare("UPDATE config_dev SET $campos WHERE id_config = 1")->execute($params);
    $sucesso = 'Configurações de e-mail atualizadas.';
}

$config = buscarConfigDev($pdo);
$dominio = urlBaseAtual();

$conexaoOk = false;
try {
    $pdo->query('SELECT 1');
    $conexaoOk = true;
} catch (Throwable $e) {
    $conexaoOk = false;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configurações — Painel do desenvolvedor</title></head>
<body>
<?php require __DIR__ . '/../includes/painel_dev_header.php'; ?>
    <div class="page-title">
        <h1>Configurações do sistema</h1>
    </div>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <div class="card" style="max-width:560px;">
        <h2>Conexão com o banco de dados</h2>
        <p><span class="status-pill<?= $conexaoOk ? ' sucesso' : ' erro' ?>"><?= $conexaoOk ? 'Conectado' : 'Falha na conexão' ?></span></p>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:10px;">
            Host, usuário, senha e nome do banco <strong>não dá pra editar por aqui</strong> — o sistema precisa desses dados só pra conseguir abrir esta tela, então eles não podem depender de já estar conectado. Pra trocar, edite o arquivo <code>config_credenciais.php</code> (raiz do projeto) direto por FTP, ou apague-o e faça login de novo — a tela de login abre o assistente de configuração automaticamente quando não encontra um banco conectado.
        </p>
    </div>

    <div class="card" style="max-width:560px; margin-top:20px;">
        <h2>Nome do sistema</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Aparece no cabeçalho do admin e na tela de login.</p>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_nome">
            <label>Nome<input type="text" name="nome_sistema" value="<?= htmlspecialchars($config['nome_sistema'] ?? 'Sistema CoderNex') ?>" required></label>
            <button type="submit" class="btn-bloco">Salvar nome</button>
        </form>
    </div>

    <div class="card" style="max-width:560px; margin-top:20px;">
        <h2>Crédito no rodapé da loja</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">O "Desenvolvido por ..." no rodapé da loja online. Deixe em branco pra continuar mostrando CoderNex.</p>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_rodape">
            <label>Nome<input type="text" name="footer_nome" value="<?= htmlspecialchars($config['footer_nome'] ?? '') ?>" placeholder="CoderNex"></label>
            <label>Link<input type="url" name="footer_link" value="<?= htmlspecialchars($config['footer_link'] ?? '') ?>" placeholder="https://codernex.com.br"></label>
            <button type="submit" class="btn-bloco">Salvar rodapé</button>
        </form>
    </div>

    <div class="card" style="max-width:560px; margin-top:20px;">
        <h2>Mercado Pago (aplicativo)</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Credenciais do aplicativo OAuth da CoderNex usado pra conectar a conta Mercado Pago de cada loja — não é o token da loja em si (isso fica em Configurações → Mercado Pago, dentro do admin normal). Deixe em branco pra continuar usando o que já está no arquivo de credenciais.</p>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_mercado_pago">
            <label>Client ID<input type="text" name="mp_app_client_id" value="<?= htmlspecialchars($config['mp_app_client_id'] ?? '') ?>" placeholder="Deixado em branco = usa o arquivo"></label>
            <label>Client Secret<input type="text" name="mp_app_client_secret" value="<?= htmlspecialchars($config['mp_app_client_secret'] ?? '') ?>" placeholder="Deixado em branco = usa o arquivo"></label>
            <label>Webhook Secret<input type="text" name="mp_webhook_secret" value="<?= htmlspecialchars($config['mp_webhook_secret'] ?? '') ?>" placeholder="Deixado em branco = usa o arquivo"></label>
            <button type="submit" class="btn-bloco">Salvar Mercado Pago</button>
        </form>
        <div style="margin-top:18px; padding-top:16px; border-top:1px solid var(--cor-borda);">
            <p style="font-size:0.85rem; font-weight:600; margin-bottom:8px;">URLs de webhook pra cadastrar no painel do Mercado Pago</p>
            <p style="color:var(--cor-texto-suave); font-size:0.8rem; margin-top:-4px; margin-bottom:12px;">Já montadas com o domínio deste site. Cada uma recebe a notificação de um fluxo de pagamento diferente — cole a que a tela do Mercado Pago pedir.</p>
            <label style="font-size:0.8rem;">Loja / Checkout<input type="text" readonly value="<?= htmlspecialchars($dominio) ?>/loja/api/notificacao_mp.php" onclick="this.select()"></label>
            <label style="font-size:0.8rem;">PDV / Caixa (Pix no balcão)<input type="text" readonly value="<?= htmlspecialchars($dominio) ?>/caixa/api/notificacao_mp.php" onclick="this.select()"></label>
            <label style="font-size:0.8rem;">Pagamento de dívida (Linha de Crédito)<input type="text" readonly value="<?= htmlspecialchars($dominio) ?>/loja/api/notificacao_divida_mp.php" onclick="this.select()"></label>
        </div>
    </div>

    <div class="card" style="max-width:560px; margin-top:20px;">
        <h2>E-mail (SMTP)</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Usado pra mandar e-mail de verificação de cadastro da loja online. Deixe em branco pra continuar usando o que já está no arquivo de credenciais.</p>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_smtp">
            <label>Host<input type="text" name="smtp_host" value="<?= htmlspecialchars($config['smtp_host'] ?? '') ?>" placeholder="Deixado em branco = usa o arquivo"></label>
            <label>Porta<input type="number" name="smtp_port" value="<?= htmlspecialchars((string) ($config['smtp_port'] ?? '')) ?>" placeholder="465 ou 587"></label>
            <label>Usuário<input type="text" name="smtp_user" value="<?= htmlspecialchars($config['smtp_user'] ?? '') ?>" placeholder="Deixado em branco = usa o arquivo"></label>
            <label>Senha<input type="password" name="smtp_pass" value="" placeholder="Deixe em branco pra manter a atual"></label>
            <label>E-mail de envio<input type="email" name="smtp_from_email" value="<?= htmlspecialchars($config['smtp_from_email'] ?? '') ?>"></label>
            <label>Nome de exibição<input type="text" name="smtp_from_name" value="<?= htmlspecialchars($config['smtp_from_name'] ?? '') ?>" placeholder="Ex: Brechó da Veve"></label>
            <button type="submit" class="btn-bloco">Salvar e-mail</button>
        </form>
    </div>
</main>
</body>
</html>
