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

// Mesmo cronômetro do carrinho — some vira "Ir para o checkout" de mentirinha
// se o cliente entra aqui mas o prazo continua correndo por trás; precisa
// continuar visível e valendo até ele realmente clicar em pagar.
$stmtV = $pdo->prepare(
    "SELECT v.valor_total, v.data_venda, cl.prazo_reserva_minutos,
            GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(v.data_venda, INTERVAL cl.prazo_reserva_minutos MINUTE))) AS segundos_restantes
     FROM vendas v
     JOIN config_loja cl ON cl.id_config = 1
     WHERE v.id_venda = :id"
);
$stmtV->execute([':id' => $id_venda]);
$venda = $stmtV->fetch();

// prazo_reserva_minutos = 0 é o "carrinho livre" (ver loja/carrinho.php) — sem
// isso a conta do cronômetro dava 0 segundos restantes (data_venda + 0
// minutos já passou) e a página achava, assim que abria, que o tempo tinha
// acabado, redirecionando o cliente de volta pro catálogo antes até de ele
// conseguir pagar. buscarCarrinhoDoCliente() só encontra vendas que ainda
// não foram pro Mercado Pago (pagamento_expira_em NULL), então esta tela
// sempre trata de um carrinho ainda em montagem, nunca de um pagamento já em
// andamento — esse caso agora vive em loja/pedido_status.php, aberto a
// partir de "Meus pedidos".
$carrinhoLivre = (int) $venda['prazo_reserva_minutos'] === 0;

$stmtIds = $pdo->prepare('SELECT DISTINCT pv.id_produto FROM itens_venda iv JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao WHERE iv.id_venda = :id');
$stmtIds->execute([':id' => $id_venda]);
$idsProdutosCheckout = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

$stmtCliente = $pdo->prepare('SELECT endereco, limite_credito, saldo_devedor FROM clientes WHERE id_cliente = :id');
$stmtCliente->execute([':id' => $id_cliente]);
$cliente = $stmtCliente->fetch();

$creditoDisponivel = (float) $cliente['limite_credito'] - (float) $cliente['saldo_devedor'];
$temLimiteCredito = (float) $cliente['limite_credito'] > 0;

$formasEntrega = $pdo->query('SELECT id_entrega, nome, tipo, prazo_dias, custo FROM formas_entrega WHERE ativo = 1 ORDER BY fixa DESC, nome')->fetchAll();

// Total só dos itens (sem a linha de "Entrega", que ainda não foi escolhida ou pode
// mudar) — é a base que o JS soma ao custo da entrega selecionada, pra o Total
// exibido acompanhar a escolha na hora, sem esperar o formulário ser enviado.
$stmtItensSubtotal = $pdo->prepare(
    "SELECT COALESCE(SUM(subtotal), 0) FROM itens_venda
     WHERE id_venda = :id AND NOT (id_produto_variacao IS NULL AND nome_produto = 'Entrega')"
);
$stmtItensSubtotal->execute([':id' => $id_venda]);
$itensSubtotal = (float) $stmtItensSubtotal->fetchColumn();

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

    <?php if (!$carrinhoLivre): ?>
    <div class="timer-card" id="timer-card">
        <svg class="icon" style="width:1.6rem; height:1.6rem;" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm-1 3v6l5 3 1-1.6-4-2.4V7Z"/></svg>
        <span class="relogio" id="contagem">--:--</span>
        <span class="texto">Tempo pra pagar antes dos itens voltarem pro estoque</span>
    </div>
    <?php endif; ?>

    <form method="post" id="form-checkout" action="/loja/ajax/gerar_checkout.php">
    <div class="layout-colunas">
        <div>
            <div class="resumo-card">
                <label>Forma de entrega
                    <select name="id_entrega" id="id_entrega">
                        <?php foreach ($formasEntrega as $f): ?>
                        <option value="<?= $f['id_entrega'] ?>" data-tipo="<?= htmlspecialchars($f['tipo']) ?>" data-custo="<?= number_format((float) $f['custo'], 2, '.', '') ?>">
                            <?= htmlspecialchars($f['nome']) ?>
                            <?= $f['prazo_dias'] !== null ? '(Prazo de ' . (int) $f['prazo_dias'] . ' dias)' : '' ?>
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
                    <span id="valor-total-checkout">R$ <?= number_format($itensSubtotal, 2, ',', '.') ?></span>
                </div>
                <button type="submit" formaction="/loja/ajax/gerar_checkout.php" class="btn-lg btn-bloco">
                    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10V8a6 6 0 1 1 12 0v2h1a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h1Zm2 0h8V8a4 4 0 1 0-8 0v2Z"/></svg>
                    Pagar com Mercado Pago
                </button>

                <?php if ($temLimiteCredito): ?>
                <button type="submit" id="btn-pagar-credito" formaction="/loja/ajax/finalizar_credito.php" class="btn-outline btn-bloco" style="margin-top:10px;">
                    <span class="rotulo-credito">Pagar com Linha de Crédito</span>
                    <span id="texto-credito-disponivel"></span>
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

// O total exibido (e o botão de Linha de Crédito) acompanha a forma de entrega
// escolhida na hora — antes disso, mudar a entrega não somava o custo dela ao
// total mostrado, só quando o formulário já tinha sido enviado.
const itensSubtotalCheckout = <?= json_encode($itensSubtotal) ?>;
const creditoDisponivelCheckout = <?= json_encode($creditoDisponivel) ?>;

function formatarMoeda(valor) {
    return 'R$ ' + valor.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d)(?=,))/g, '.');
}

function atualizarTotalCheckout() {
    const select = document.getElementById('id_entrega');
    const opcao = select.options[select.selectedIndex];
    const custoEntrega = opcao ? parseFloat(opcao.dataset.custo || '0') : 0;
    const total = itensSubtotalCheckout + custoEntrega;

    document.getElementById('valor-total-checkout').textContent = formatarMoeda(total);

    const btnCredito = document.getElementById('btn-pagar-credito');
    if (btnCredito) {
        const textoCredito = document.getElementById('texto-credito-disponivel');
        const insuficiente = creditoDisponivelCheckout < total;
        btnCredito.disabled = insuficiente;
        textoCredito.textContent = insuficiente
            ? ' (insuficiente: ' + formatarMoeda(creditoDisponivelCheckout) + ')'
            : ' (disponível: ' + formatarMoeda(creditoDisponivelCheckout) + ')';
    }
}
document.getElementById('id_entrega').addEventListener('change', atualizarTotalCheckout);
atualizarTotalCheckout();

<?php if (!$carrinhoLivre): ?>
let restante = <?= (int) $venda['segundos_restantes'] ?>;
const idsProdutosCheckout = <?= json_encode($idsProdutosCheckout) ?>;

function formatarTempo(segundos) {
    const min = Math.floor(segundos / 60);
    const seg = segundos % 60;
    return min + ':' + String(seg).padStart(2, '0');
}

function atualizarContagemCheckout() {
    const painel = document.getElementById('timer-card');
    const rotulo = document.getElementById('contagem');
    if (!rotulo) { return; }
    rotulo.textContent = formatarTempo(restante);
    if (painel) { painel.classList.toggle('urgente', restante <= 60); }

    if (restante <= 0) {
        clearInterval(intervaloCheckout);
        const ids = idsProdutosCheckout.join(',');
        window.location.href = '/loja/index.php' + (ids ? '?voltou=' + ids : '');
        return;
    }
    restante--;
}

let intervaloCheckout = null;
atualizarContagemCheckout();
intervaloCheckout = setInterval(atualizarContagemCheckout, 1000);
<?php endif; ?>
</script>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
