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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Checkout</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm-1.5 14.5-4-4 1.4-1.4 2.6 2.6 6.6-6.6 1.4 1.4Z" fill="currentColor"/></svg></span>
        <h1>Checkout</h1>
    </div>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <form method="post" id="form-checkout" action="/loja/ajax/gerar_checkout.php">
    <div class="layout-colunas">
        <div>
            <div class="resumo-card">
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
                </label>
                <div id="campo-endereco">
                    <label>Endereço de entrega
                        <textarea name="endereco"><?= htmlspecialchars($cliente['endereco'] ?? '') ?></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="painel-lateral">
            <div class="resumo-card">
                <div class="resumo-total" style="border-top:none; margin-top:0; padding-top:0;">
                    <span>Total</span>
                    <span>R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></span>
                </div>
                <button type="submit" formaction="/loja/ajax/gerar_checkout.php" class="btn-lg btn-bloco">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h1Zm2 0h8V8a4 4 0 1 0-8 0v2Z"/></svg>
                    Pagar com Mercado Pago
                </button>

                <?php if ($temLimiteCredito): ?>
                <button type="submit" formaction="/loja/ajax/finalizar_credito.php" class="btn-outline btn-bloco" style="margin-top:10px;" <?= $creditoDisponivel < (float) $venda['valor_total'] ? 'disabled' : '' ?>>
                    Pagar com Linha de Crédito
                    <?= $creditoDisponivel < (float) $venda['valor_total']
                        ? ' (insuficiente: R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')'
                        : ' (disponível: R$ ' . number_format($creditoDisponivel, 2, ',', '.') . ')' ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
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
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
