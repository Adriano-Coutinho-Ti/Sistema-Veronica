<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

if (caixaAbertoAtual($pdo)) {
    header('Location: /caixa/index.php');
    exit;
}

$multiCaixa = quantidadeCaixas($pdo) > 1;
$numeroCaixa = 1;

if ($multiCaixa) {
    // Com mais de um caixa, só se chega aqui a partir de caixa/selecionar.php
    // escolhendo um número específico -- sem ?numero=, ou com um número já
    // aberto por outro (checado de novo aqui, contra corrida com a tela de
    // seleção), volta pra lá em vez de deixar abrir o caixa errado.
    $numeroCaixa = (int) ($_GET['numero'] ?? $_POST['numero_caixa'] ?? 0);
    $qtd = quantidadeCaixas($pdo);
    if ($numeroCaixa < 1 || $numeroCaixa > $qtd) {
        header('Location: /caixa/selecionar.php');
        exit;
    }
    $stmtOcupado = $pdo->prepare("SELECT 1 FROM caixa_sessoes WHERE numero_caixa = :n AND status = 'aberto'");
    $stmtOcupado->execute([':n' => $numeroCaixa]);
    if ($stmtOcupado->fetchColumn()) {
        header('Location: /caixa/selecionar.php?ocupado=1');
        exit;
    }
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor_inicial = (float) str_replace(',', '.', $_POST['valor_inicial'] ?? '0');
    if ($valor_inicial < 0) {
        $erro = 'Informe um valor inicial válido.';
    } else {
        try {
            $pdo->prepare('INSERT INTO caixa_sessoes (numero_caixa, valor_inicial, aberto_por, status) VALUES (:n, :v, :u, "aberto")')
                ->execute([':n' => $numeroCaixa, ':v' => $valor_inicial, ':u' => $_SESSION['id_usuario']]);
            $_SESSION['id_caixa_selecionado'] = (int) $pdo->lastInsertId();
            header('Location: /caixa/index.php');
            exit;
        } catch (PDOException $e) {
            // Índice único de numero_caixa_aberto -- outro usuário abriu esse
            // mesmo caixa entre a checagem acima e este INSERT.
            if ($e->getCode() === '23000') {
                header('Location: /caixa/selecionar.php?ocupado=1');
                exit;
            }
            throw $e;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Abrir caixa</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Abrir caixa<?= $multiCaixa ? ' ' . $numeroCaixa : '' ?></h1>
            <span class="subtitulo">Informe o valor inicial pra começar o dia</span>
        </div>
    </div>

    <?php if (isset($_GET['fechado'])): ?><p class="alert alert-sucesso">Caixa fechado com sucesso.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card" style="max-width:420px;">
        <h2>Valor inicial</h2>
        <form method="post">
            <?php if ($multiCaixa): ?><input type="hidden" name="numero_caixa" value="<?= $numeroCaixa ?>"><?php endif; ?>
            <label>Valor inicial em dinheiro (R$)<input type="text" name="valor_inicial" value="0,00" required></label>
            <button type="submit" class="btn-bloco">Abrir caixa</button>
        </form>
        <?php if ($multiCaixa): ?><a href="/caixa/selecionar.php" class="btn-texto btn-sm" style="margin-top:10px;">← Escolher outro caixa</a><?php endif; ?>
    </div>
</main>
</body>
</html>
