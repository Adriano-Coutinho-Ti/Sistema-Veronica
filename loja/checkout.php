<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

liberarReservasExpiradas($pdo);

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

if (!$id_venda) {
    header('Location: /loja/carrinho.php');
    exit;
}

$stmtV = $pdo->prepare('SELECT valor_total FROM vendas WHERE id_venda = :id');
$stmtV->execute([':id' => $id_venda]);
$venda = $stmtV->fetch();

$stmtCliente = $pdo->prepare('SELECT endereco, limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
$temLimiteCredito = (float) $cliente['limite_credito'] > 0;

$formasEntrega = $pdo->query('SELECT id_entrega, nome, tipo, prazo_dias, custo FROM formas_entrega WHERE ativo = 1 ORDER BY fixa DESC, nome')->fetchAll();

$erro = $_GET['erro'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Checkout</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Checkout</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <p>Total dos itens: R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></p>

    <form method="post" action="/loja/ajax/gerar_checkout.php">
        <label>Forma de entrega
            <select name="id_entrega" id="id_entrega">
                <?php foreach ($formasEntrega as $f): ?>
                <option value="<?= $f['id_entrega'] ?>" data-tipo="<?= htmlspecialchars($f['tipo']) ?>">
                    <?= htmlspecialchars($f['nome']) ?>
                    <?= $f['prazo_dias'] !== null ? '(' . (int) $f['prazo_dias'] . ' dias)' : '' ?>
                    — R$ <?= number_format($f['custo'], 2, ',', '.') ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label><br>
        <div id="campo-endereco">
            <label>Endereço de entrega<br>
                <textarea name="endereco"><?= htmlspecialchars($cliente['endereco'] ?? '') ?></textarea>
            </label>
        </div>
        <button type="submit" formaction="/loja/ajax/gerar_checkout.php">Pagar com Mercado Pago</button>

        <?php if ($temLimiteCredito): ?>
        <button type="submit" formaction="/loja/ajax/finalizar_credito.php" <?= $creditoDisponivel < (float) $venda['valor_total'] ? 'disabled' : '' ?>>
            Pagar com minha Linha de Crédito
            <?= $creditoDisponivel < (float) $venda['valor_total']
                ? ' (crédito insuficiente: disponível R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')'
                : ' (disponível: R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')' ?>
        </button>
        <?php endif; ?>
    </form>

<script>
function atualizarCampoEndereco() {
    const select = document.getElementById('id_entrega');
    const opcao = select.options[select.selectedIndex];
    const tipo = opcao ? opcao.dataset.tipo : null;
    document.getElementById('campo-endereco').style.display = tipo === 'retirada' ? 'none' : '';
}
document.getElementById('id_entrega').addEventListener('change', atualizarCampoEndereco);
atualizarCampoEndereco();
</script>
</main>
</body>
</html>
