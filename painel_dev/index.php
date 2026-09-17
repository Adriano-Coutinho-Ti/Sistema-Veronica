<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_dev.php';
require_once __DIR__ . '/../includes/config_dev.php';
require_once __DIR__ . '/../includes/caixa.php';
require_once __DIR__ . '/../includes/mp_client.php';
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
    $taxaMarketplace = (float) str_replace(',', '.', $_POST['marketplace_fee_percentual'] ?? '');
    if ($taxaMarketplace <= 0 || $taxaMarketplace > 100) {
        $erro = 'A taxa de marketplace deve ser maior que 0% e no máximo 100%.';
    } else {
        $pdo->prepare(
            'UPDATE config_dev SET mp_app_client_id = :id, mp_app_client_secret = :secret, mp_webhook_secret = :webhook, marketplace_fee_percentual = :taxa WHERE id_config = 1'
        )->execute([
            ':id' => trim($_POST['mp_app_client_id'] ?? '') ?: null,
            ':secret' => trim($_POST['mp_app_client_secret'] ?? '') ?: null,
            ':webhook' => trim($_POST['mp_webhook_secret'] ?? '') ?: null,
            ':taxa' => $taxaMarketplace,
        ]);
        $sucesso = 'Credenciais do Mercado Pago atualizadas.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_rodape') {
    $pdo->prepare('UPDATE config_dev SET footer_nome = :nome, footer_link = :link WHERE id_config = 1')->execute([
        ':nome' => trim($_POST['footer_nome'] ?? '') ?: null,
        ':link' => trim($_POST['footer_link'] ?? '') ?: null,
    ]);
    $sucesso = 'Crédito do rodapé atualizado.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_smtp') {
    $porta = trim($_POST['smtp_port'] ?? '');
    // O campo de senha agora vem preenchido com o valor real salvo (painel do
    // dev, não tem por que esconder) — então, diferente de antes, o que for
    // enviado aqui É a nova senha, igual a todo o resto do formulário.
    $pdo->prepare(
        'UPDATE config_dev SET smtp_host = :host, smtp_port = :port, smtp_user = :user, smtp_pass = :pass, smtp_from_email = :from_email, smtp_from_name = :from_name WHERE id_config = 1'
    )->execute([
        ':host' => trim($_POST['smtp_host'] ?? '') ?: null,
        ':port' => $porta !== '' ? (int) $porta : null,
        ':user' => trim($_POST['smtp_user'] ?? '') ?: null,
        ':pass' => ($_POST['smtp_pass'] ?? '') ?: null,
        ':from_email' => trim($_POST['smtp_from_email'] ?? '') ?: null,
        ':from_name' => trim($_POST['smtp_from_name'] ?? '') ?: null,
    ]);
    $sucesso = 'Configurações de e-mail atualizadas.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_caixas') {
    $quantidade = (int) ($_POST['quantidade_caixas'] ?? 0);
    $compartilhados = isset($_POST['caixas_compartilhados']) ? 1 : 0;

    if ($quantidade < 1) {
        $erro = 'A quantidade de caixas precisa ser pelo menos 1.';
    } else {
        $abertosAgora = (int) $pdo->query("SELECT COUNT(*) FROM caixa_sessoes WHERE status = 'aberto'")->fetchColumn();
        if ($quantidade < $abertosAgora) {
            $erro = "Existem {$abertosAgora} caixas abertos agora — feche os caixas extras antes de reduzir a quantidade.";
        } else {
            $pdo->prepare('UPDATE config_dev SET quantidade_caixas = :q, caixas_compartilhados = :c WHERE id_config = 1')
                ->execute([':q' => $quantidade, ':c' => $compartilhados]);
            $sucesso = 'Configuração de caixas atualizada.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_terminais') {
    $qtdAtual = quantidadeCaixas($pdo);
    for ($numero = 1; $numero <= $qtdAtual; $numero++) {
        $terminalEscolhido = trim($_POST['terminal_caixa_' . $numero] ?? '');
        if ($terminalEscolhido === '') {
            $pdo->prepare('DELETE FROM caixa_terminais_point WHERE numero_caixa = :n')->execute([':n' => $numero]);
        } else {
            $pdo->prepare(
                'INSERT INTO caixa_terminais_point (numero_caixa, terminal_id) VALUES (:n, :t)
                 ON DUPLICATE KEY UPDATE terminal_id = :t2'
            )->execute([':n' => $numero, ':t' => $terminalEscolhido, ':t2' => $terminalEscolhido]);
        }
    }
    $sucesso = 'Maquininhas atualizadas.';
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

// Lista de maquininhas Point ao vivo, direto da API, usando o token da loja
// já conectada -- não tem como cadastrar isso manualmente, o terminal_id só
// existe depois que o lojista associa a maquininha a uma loja/caixa dentro
// do próprio app do Mercado Pago.
$terminaisDisponiveis = [];
$erroTerminais = '';
$mpConfigAtual = mpConfig($pdo);
if ($mpConfigAtual && !empty($mpConfigAtual['mp_access_token'])) {
    try {
        $respostaTerminais = mpChamarApi('GET', 'https://api.mercadopago.com/terminals/v1/list', null, $mpConfigAtual['mp_access_token']);
        if ($respostaTerminais['http_code'] < 300) {
            $terminaisDisponiveis = $respostaTerminais['dados']['data']['terminals'] ?? [];
        } else {
            $erroTerminais = 'Não foi possível buscar as maquininhas agora.';
        }
    } catch (Throwable $e) {
        $erroTerminais = 'Não foi possível buscar as maquininhas agora.';
    }
} else {
    $erroTerminais = 'Conecte a loja ao Mercado Pago primeiro (em Configurações) pra listar as maquininhas.';
}

$vinculosAtuais = $pdo->query('SELECT numero_caixa, terminal_id FROM caixa_terminais_point')->fetchAll(PDO::FETCH_KEY_PAIR);
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
        <div style="margin-top:12px; font-size:0.85rem;">
            <p style="margin:0 0 4px;"><strong>Host:</strong> <?= htmlspecialchars($host ?? '') ?></p>
            <p style="margin:0 0 4px;"><strong>Nome do banco:</strong> <?= htmlspecialchars($dbname ?? '') ?></p>
            <p style="margin:0 0 4px;"><strong>Usuário:</strong> <?= htmlspecialchars($username ?? '') ?></p>
            <p style="margin:0;"><strong>Senha:</strong> <?= htmlspecialchars($password ?? '') ?></p>
        </div>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:10px;">
            Pra trocar qualquer um desses dados, use o botão abaixo — vai pedir a senha de instalação de novo, a mesma usada na primeira configuração.
        </p>
        <a href="/painel_dev/login.php?editar_banco=1" class="btn-outline btn-sm" style="margin-top:6px;">Editar dados do banco</a>
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
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Credenciais do aplicativo cadastrado em <a href="https://www.mercadopago.com.br/developers" target="_blank" rel="noopener">Mercado Pago Developers</a> — são elas que permitem o botão "Conectar Mercado Pago" funcionar (cada loja conecta a própria conta através desse aplicativo). Pegue o Client ID e o Client Secret na página do seu aplicativo, aba de credenciais de produção. Não é o token de pagamento de uma loja específica — isso cada lojista configura na própria conta, em Configurações → Mercado Pago.</p>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_mercado_pago">
            <label>Client ID<input type="text" name="mp_app_client_id" value="<?= htmlspecialchars($config['mp_app_client_id'] ?? '') ?>" placeholder="Copie da página do aplicativo"></label>
            <label>Client Secret<input type="text" name="mp_app_client_secret" value="<?= htmlspecialchars($config['mp_app_client_secret'] ?? '') ?>" placeholder="Copie da página do aplicativo"></label>
            <label>Webhook Secret<input type="text" name="mp_webhook_secret" value="<?= htmlspecialchars($config['mp_webhook_secret'] ?? '') ?>" placeholder="Aparece depois de cadastrar a URL de webhook abaixo"></label>
            <label>Taxa de marketplace (%)<input type="text" name="marketplace_fee_percentual" value="<?= htmlspecialchars((string) ($config['marketplace_fee_percentual'] ?? '1.00')) ?>" required></label>
            <p style="color:var(--cor-texto-suave); font-size:0.8rem; margin-top:-8px;">Percentual cobrado em toda venda paga pelo Mercado Pago (loja, PDV e pagamento de dívida). Precisa ser maior que 0%.</p>
            <button type="submit" class="btn-bloco">Salvar Mercado Pago</button>
        </form>
        <div style="margin-top:18px; padding-top:16px; border-top:1px solid var(--cor-borda);">
            <p style="font-size:0.85rem; font-weight:600; margin-bottom:8px;">Redirect URI (cadastrar no aplicativo, aba OAuth)</p>
            <p style="color:var(--cor-texto-suave); font-size:0.8rem; margin-top:-4px; margin-bottom:12px;">O Mercado Pago só aceita "Conectar" se essa URL bater <strong>exatamente</strong> com a cadastrada no aplicativo — confira lá se está assim, sem barra a mais no final nem "www.".</p>
            <label style="font-size:0.8rem;">Redirect URI<input type="text" readonly value="<?= htmlspecialchars($dominio) ?>/integracoes/mercado_pago/callback.php" onclick="this.select()"></label>
        </div>
        <div style="margin-top:18px; padding-top:16px; border-top:1px solid var(--cor-borda);">
            <p style="font-size:0.85rem; font-weight:600; margin-bottom:8px;">URL de webhook (cadastrar no aplicativo, aba Webhooks)</p>
            <p style="color:var(--cor-texto-suave); font-size:0.8rem; margin-top:-4px; margin-bottom:12px;">O Mercado Pago só aceita uma URL de webhook por aplicativo — esta única URL recebe a notificação de todos os fluxos de pagamento (loja, PDV e pagamento de dívida) e decide sozinha qual é qual. Ao cadastrar essa URL lá, o Mercado Pago mostra uma "assinatura secreta" — cole ela no campo Webhook Secret acima.</p>
            <label style="font-size:0.8rem;">URL de webhook<input type="text" readonly value="<?= htmlspecialchars($dominio) ?>/integracoes/mercado_pago/webhook.php" onclick="this.select()"></label>
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
            <label>Senha<input type="text" name="smtp_pass" value="<?= htmlspecialchars($config['smtp_pass'] ?? '') ?>" autocomplete="off"></label>
            <label>E-mail de envio<input type="email" name="smtp_from_email" value="<?= htmlspecialchars($config['smtp_from_email'] ?? '') ?>"></label>
            <label>Nome de exibição<input type="text" name="smtp_from_name" value="<?= htmlspecialchars($config['smtp_from_name'] ?? '') ?>" placeholder="Ex: Brechó da Veve"></label>
            <button type="submit" class="btn-bloco">Salvar e-mail</button>
        </form>
    </div>

    <div class="card" style="max-width:560px; margin-top:20px;">
        <h2>PDV — Caixas</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Quantos caixas físicos o sistema vai trabalhar. Com 1 (padrão), o sistema funciona exatamente como sempre funcionou — qualquer usuário atende no único caixa aberto, sem nenhuma tela extra. Com mais de 1, cada usuário escolhe em qual caixa vai atender.</p>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_caixas">
            <label>Quantidade de caixas<input type="number" name="quantidade_caixas" min="1" value="<?= htmlspecialchars((string) ($config['quantidade_caixas'] ?? 1)) ?>" required></label>
            <label style="flex-direction:row; align-items:center; gap:8px;">
                <input type="checkbox" name="caixas_compartilhados" value="1" style="width:auto;" <?= !empty($config['caixas_compartilhados']) || !isset($config['caixas_compartilhados']) ? 'checked' : '' ?>>
                Caixas compartilhados (qualquer usuário pode atender num caixa já aberto por outra pessoa)
            </label>
            <p style="color:var(--cor-texto-suave); font-size:0.8rem; margin-top:-8px;">Desmarcado: só quem abriu um caixa pode atender nele — outro usuário precisa esperar fechar (ou abrir um caixa diferente). Só faz diferença com mais de 1 caixa.</p>
            <button type="submit" class="btn-bloco">Salvar caixas</button>
        </form>
    </div>

    <div class="card" style="max-width:560px; margin-top:20px;">
        <h2>PDV — Maquininhas (Point)</h2>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Vincule cada caixa a uma maquininha física. Uma mesma maquininha pode atender mais de um caixa (loja com só 1 maquininha pra vários caixas funciona normalmente — o sistema trava pra não mandar duas cobranças ao mesmo tempo pra ela). Caixa sem maquininha vinculada continua com lançamento manual de Débito/Crédito, como sempre foi. A maquininha só aparece na lista abaixo depois de associada a uma loja/caixa dentro do próprio app do Mercado Pago.</p>
        <?php if ($erroTerminais): ?><p class="alert alert-erro"><?= htmlspecialchars($erroTerminais) ?></p><?php endif; ?>
        <?php if (!$erroTerminais && empty($terminaisDisponiveis)): ?><p class="alert alert-erro">Nenhuma maquininha encontrada na conta conectada.</p><?php endif; ?>
        <?php if (!empty($terminaisDisponiveis)): ?>
        <form method="post">
            <input type="hidden" name="acao" value="atualizar_terminais">
            <?php for ($numero = 1; $numero <= quantidadeCaixas($pdo); $numero++): ?>
                <label>Caixa <?= $numero ?>
                    <select name="terminal_caixa_<?= $numero ?>">
                        <option value="">— sem maquininha (lançamento manual) —</option>
                        <?php foreach ($terminaisDisponiveis as $terminal): ?>
                            <option value="<?= htmlspecialchars($terminal['id']) ?>" <?= ($vinculosAtuais[$numero] ?? '') === $terminal['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($terminal['id']) ?><?= !empty($terminal['external_pos_id']) ? ' (' . htmlspecialchars($terminal['external_pos_id']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endfor; ?>
            <button type="submit" class="btn-bloco">Salvar maquininhas</button>
        </form>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
