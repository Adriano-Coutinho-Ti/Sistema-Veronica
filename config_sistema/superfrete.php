<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/superfrete.php';
exigirAdmin();

// Sem o recurso ligado pelo dev, a tela nem existe.
if (!superfreteAtivo($pdo)) {
    header('Location: /dashboard.php');
    exit;
}

$etiquetas = superfreteEtiquetasAtivas($pdo);
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cep = superfreteSomenteDigitos($_POST['cep_origem'] ?? '');
    $servicos = array_values(array_filter(array_map('intval', $_POST['servicos'] ?? []), fn($id) => isset(SUPERFRETE_SERVICOS[$id])));

    if (strlen($cep) !== 8) {
        $erro = 'Informe um CEP de origem válido (8 dígitos).';
    } elseif (empty($servicos)) {
        $erro = 'Ative ao menos um serviço de entrega.';
    } else {
        $tokenNovo = trim($_POST['token'] ?? '');
        $tokenAtual = (string) (superfreteConfigLojista($pdo)['superfrete_token'] ?? '');
        $token = $etiquetas ? ($tokenNovo !== '' ? $tokenNovo : $tokenAtual) : $tokenAtual;
        if (!empty($_POST['remover_token'])) {
            $token = '';
        }
        $pdo->prepare('UPDATE config_loja SET superfrete_cep_origem = :cep, superfrete_servicos = :s, superfrete_seguro = :seg, superfrete_token = :t WHERE id_config = 1')
            ->execute([':cep' => $cep, ':s' => implode(',', $servicos), ':seg' => isset($_POST['seguro']) ? 1 : 0, ':t' => $token !== '' ? $token : null]);
        $sucesso = 'Configurações da SuperFrete salvas.';
    }
}

$cfg = superfreteConfigLojista($pdo);
$ativos = superfreteServicosAtivos($pdo);
$temToken = trim((string) ($cfg['superfrete_token'] ?? '')) !== '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>SuperFrete</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <div>
            <h1>SuperFrete</h1>
            <span class="subtitulo">Frete calculado pelo CEP na loja online<?= $etiquetas ? ' e impressão de etiquetas' : '' ?></span>
        </div>
    </div>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>

    <div class="card" style="max-width:640px;">
    <form method="post">
        <label>CEP de origem (de onde você despacha)
            <input type="text" name="cep_origem" inputmode="numeric" maxlength="9" value="<?= htmlspecialchars($cfg['superfrete_cep_origem'] ?? '') ?>" placeholder="00000-000" required>
        </label>

        <h3 style="margin-top:20px;">Serviços de entrega oferecidos ao cliente</h3>
        <?php foreach (SUPERFRETE_SERVICOS as $id => $nome): ?>
        <label style="display:block;"><input type="checkbox" name="servicos[]" value="<?= $id ?>" <?= in_array($id, $ativos, true) ? 'checked' : '' ?>> <?= htmlspecialchars($nome) ?></label>
        <?php endforeach; ?>

        <label style="display:block; margin-top:14px;"><input type="checkbox" name="seguro" <?= !empty($cfg['superfrete_seguro']) ? 'checked' : '' ?>> Declarar o valor dos itens como seguro (mínimo de R$ <?= number_format(SUPERFRETE_SEGURO_MINIMO, 2, ',', '.') ?>)</label>

        <?php if ($etiquetas): ?>
        <h3 style="margin-top:24px;">Etiquetas</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem;">Para imprimir etiquetas você precisa da sua própria conta na SuperFrete, com saldo na carteira, e do token de integração dela (painel da SuperFrete → Integrações). Sem o token, o botão de etiqueta fica bloqueado.</p>
        <label>Token da SuperFrete
            <input type="password" name="token" autocomplete="off" placeholder="<?= $temToken ? 'Token já salvo — deixe em branco para manter' : 'Cole o seu token' ?>">
        </label>
        <?php if ($temToken): ?>
        <label style="display:block;"><input type="checkbox" name="remover_token"> Remover o token salvo</label>
        <?php endif; ?>
        <p><span class="status-pill<?= $temToken ? ' sucesso' : ' erro' ?>"><?= $temToken ? 'Etiquetas liberadas' : 'Etiquetas bloqueadas — falta o token' ?></span></p>
        <?php endif; ?>

        <button type="submit" class="btn-bloco" style="margin-top:14px;">Salvar</button>
    </form>
    </div>
</main>
</body>
</html>
