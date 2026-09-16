<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

$qtd = quantidadeCaixas($pdo);
if ($qtd <= 1) {
    header('Location: ' . (caixaAbertoAtual($pdo) ? '/caixa/index.php' : '/caixa/abertura.php'));
    exit;
}

$compartilhados = caixasCompartilhados($pdo);
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'entrar') {
    $numero = (int) ($_POST['numero_caixa'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM caixa_sessoes WHERE numero_caixa = :n AND status = 'aberto'");
    $stmt->execute([':n' => $numero]);
    $caixa = $stmt->fetch();

    if (!$caixa) {
        $erro = 'Esse caixa não está mais aberto.';
    } elseif (!$compartilhados && (int) $caixa['aberto_por'] !== (int) $_SESSION['id_usuario']) {
        $erro = 'Esse caixa está sendo usado por outra pessoa.';
    } else {
        $_SESSION['id_caixa_selecionado'] = (int) $caixa['id_caixa'];
        header('Location: /caixa/index.php');
        exit;
    }
}

$abertos = $pdo->query(
    "SELECT cs.numero_caixa, cs.aberto_por, cs.data_abertura, u.nome AS nome_operador
     FROM caixa_sessoes cs
     JOIN usuarios u ON u.id_usuario = cs.aberto_por
     WHERE cs.status = 'aberto'"
)->fetchAll();

$abertosPorNumero = [];
foreach ($abertos as $linha) {
    $abertosPorNumero[(int) $linha['numero_caixa']] = $linha;
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Escolher caixa</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Escolher caixa</h1>
            <span class="subtitulo">Este sistema trabalha com <?= $qtd ?> caixas — escolha em qual você vai atender</span>
        </div>
    </div>

    <?php if (isset($_GET['fechado'])): ?><p class="alert alert-sucesso">Caixa fechado com sucesso.</p><?php endif; ?>
    <?php if (isset($_GET['ocupado'])): ?><p class="alert alert-erro">Esse caixa acabou de ser aberto por outra pessoa. Escolha outro.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="grade-cards">
        <?php for ($numero = 1; $numero <= $qtd; $numero++): ?>
            <?php $caixaAberto = $abertosPorNumero[$numero] ?? null; ?>
            <?php $souEu = $caixaAberto && (int) $caixaAberto['aberto_por'] === (int) $_SESSION['id_usuario']; ?>
            <?php $posseLiberada = $caixaAberto && ($compartilhados || $souEu); ?>
            <div class="card">
                <h2>Caixa <?= $numero ?></h2>
                <?php if ($caixaAberto): ?>
                    <p><span class="status-pill<?= $posseLiberada ? ' sucesso' : '' ?>">Aberto por <?= htmlspecialchars($caixaAberto['nome_operador']) ?><?= $souEu ? ' (você)' : '' ?></span></p>
                    <p style="color:var(--cor-texto-suave); font-size:0.85rem;">Desde <?= htmlspecialchars(date('d/m/Y H:i', strtotime($caixaAberto['data_abertura']))) ?></p>
                    <?php if ($posseLiberada): ?>
                        <form method="post" style="margin-top:10px;">
                            <input type="hidden" name="acao" value="entrar">
                            <input type="hidden" name="numero_caixa" value="<?= $numero ?>">
                            <button type="submit" class="btn-bloco">Entrar</button>
                        </form>
                    <?php else: ?>
                        <button type="button" class="btn-bloco" disabled style="opacity:0.5; cursor:not-allowed;">Ocupado</button>
                    <?php endif; ?>
                <?php else: ?>
                    <p><span class="status-pill">Fechado</span></p>
                    <a href="/caixa/abertura.php?numero=<?= $numero ?>" class="btn btn-bloco" style="text-align:center; margin-top:10px;">Abrir caixa <?= $numero ?></a>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</main>
</body>
</html>
