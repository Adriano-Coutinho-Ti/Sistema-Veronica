<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/caixa.php';
exigirLogin();

if (caixaAbertoAtual($pdo)) {
    header('Location: /caixa/index.php');
    exit;
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $valor_inicial = (float) str_replace(',', '.', $_POST['valor_inicial'] ?? '0');
    if ($valor_inicial < 0) {
        $erro = 'Informe um valor inicial válido.';
    } else {
        $pdo->prepare('INSERT INTO caixa_sessoes (valor_inicial, aberto_por, status) VALUES (:v, :u, "aberto")')
            ->execute([':v' => $valor_inicial, ':u' => $_SESSION['id_usuario']]);
        header('Location: /caixa/index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Abrir caixa</title></head>
<body>
    <h1>Abrir caixa</h1>
    <?php if (isset($_GET['fechado'])): ?><p style="color:green;">Caixa fechado com sucesso.</p><?php endif; ?>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <label>Valor inicial em dinheiro (R$)<br><input type="text" name="valor_inicial" value="0,00" required></label><br>
        <button type="submit">Abrir caixa</button>
    </form>
</body>
</html>
