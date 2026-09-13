<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$sucesso = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prazo = (int) ($_POST['prazo_reserva_minutos'] ?? 0);
    $imprimirAuto = isset($_POST['imprimir_automatico']) ? 1 : 0;

    if ($prazo < 1) {
        $erro = 'O tempo de espera do carrinho precisa ser de pelo menos 1 minuto.';
    } else {
        $pdo->prepare('UPDATE config_loja SET prazo_reserva_minutos = :prazo, imprimir_automatico = :imp WHERE id_config = 1')
            ->execute([':prazo' => $prazo, ':imp' => $imprimirAuto]);
        $sucesso = 'Configurações salvas.';
    }
}

$config = $pdo->query('SELECT prazo_reserva_minutos, imprimir_automatico FROM config_loja WHERE id_config = 1')->fetch();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configurações do PDV</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h16M6 20V10a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v10M9 8V6a3 3 0 0 1 6 0v2M10 14h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Configurações do PDV</h1>
            <span class="subtitulo">Carrinho, impressão e comprovante</span>
        </div>
    </div>

    <?php if ($sucesso): ?><p class="alert alert-sucesso"><?= htmlspecialchars($sucesso) ?></p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card" style="max-width:560px;">
        <h2>Carrinho da loja online</h2>
        <form method="post">
            <label>Tempo de espera antes de liberar o carrinho de volta pro estoque (minutos)
                <input type="number" min="1" name="prazo_reserva_minutos" value="<?= (int) $config['prazo_reserva_minutos'] ?>" required>
            </label>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px; margin-bottom:16px;">Enquanto o cliente está com um produto no carrinho (ou pagando), esse produto fica reservado por esse tempo. Se ele não finalizar a compra, o produto volta a ficar disponível pros outros clientes.</p>

            <h2 style="margin-top:8px;">Impressão do comprovante</h2>
            <div class="lista-checkbox">
                <label>
                    <input type="checkbox" name="imprimir_automatico" <?= $config['imprimir_automatico'] ? 'checked' : '' ?>>
                    Abrir a impressão automaticamente ao finalizar uma venda no PDV
                </label>
            </div>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:8px; margin-bottom:20px;">
                O comprovante é uma página normal — pra imprimir numa impressora térmica Bluetooth, pareie a impressora no telefone (geralmente usando um app como o RawBT, comum nesse tipo de impressora popular) e escolha ela na tela de impressão do navegador. Se o operador estiver no computador, a impressão vai normalmente pra impressora do Windows.
            </p>

            <button type="submit" class="btn-bloco">Salvar configurações</button>
        </form>
    </div>
</main>
</body>
</html>
