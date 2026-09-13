<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/pedidos.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT v.*, fe.tipo AS entrega_tipo, fe.nome AS entrega_nome
     FROM vendas v
     LEFT JOIN formas_entrega fe ON fe.id_entrega = v.id_entrega
     WHERE v.id_venda = :id AND v.id_cliente = :ic AND v.origem = 'loja'"
);
$stmt->execute([':id' => $id_venda, ':ic' => $id_cliente]);
$venda = $stmt->fetch();

if (!$venda) {
    http_response_code(404);
    echo 'Pedido não encontrado.';
    exit;
}

$itens = $pdo->prepare(
    "SELECT iv.nome_produto, iv.descricao_combinacao, iv.quantidade, iv.subtotal, pv.id_produto,
            (SELECT pf.caminho_arquivo FROM produto_fotos pf WHERE pf.id_produto = pv.id_produto ORDER BY pf.ordem LIMIT 1) AS foto
     FROM itens_venda iv
     LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
     WHERE iv.id_venda = :id"
);
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

$rotulo = rotuloStatusPedido($venda['status'], $venda['status_entrega'], $venda['entrega_tipo']);
$classePill = classePillStatusPedido($venda['status'], $venda['status_entrega']);

// Retirada na loja não tem endereço de entrega — o cliente precisa saber
// ONDE ir buscar, então mostramos o endereço e o telefone da loja aqui
// sempre que a forma de entrega escolhida foi "retirar na loja".
$dadosRetirada = null;
if ($venda['entrega_tipo'] === 'retirada') {
    $dadosRetirada = $pdo->query('SELECT endereco_loja, whatsapp_loja, horario_atendimento FROM config_loja WHERE id_config = 1')->fetch();
}

// "Você também pode gostar" — mesma seção da página de produto, mas sem um
// produto-base pra puxar a categoria: mostra outros itens ativos com
// estoque disponível, sorteados, excluindo o que já está neste pedido.
$idsNoPedido = array_filter(array_column($listaItens, 'id_produto'));
$placeholdersExcluir = !empty($idsNoPedido) ? implode(',', array_fill(0, count($idsNoPedido), '?')) : null;
$sqlRelacionados = "SELECT DISTINCT p2.id_produto, p2.nome, p2.preco_base
     FROM produtos p2
     JOIN produto_variacoes pv2 ON pv2.id_produto = p2.id_produto
     WHERE p2.ativo = 1 AND (pv2.estoque - pv2.estoque_reservado) > 0"
     . ($placeholdersExcluir ? " AND p2.id_produto NOT IN ($placeholdersExcluir)" : '')
     . ' ORDER BY RAND() LIMIT 8';
$stmtRelacionados = $pdo->prepare($sqlRelacionados);
$stmtRelacionados->execute(array_values($idsNoPedido));
$listaRelacionados = $stmtRelacionados->fetchAll();

$fotosRelacionados = [];
if (!empty($listaRelacionados)) {
    $idsRel = array_column($listaRelacionados, 'id_produto');
    $placeholders = implode(',', array_fill(0, count($idsRel), '?'));
    $stmtFotosRel = $pdo->prepare("SELECT id_produto, caminho_arquivo FROM produto_fotos WHERE id_produto IN ($placeholders) ORDER BY id_produto, ordem");
    $stmtFotosRel->execute($idsRel);
    foreach ($stmtFotosRel->fetchAll() as $f) {
        $fotosRelacionados[(int) $f['id_produto']][] = $f['caminho_arquivo'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Pedido #<?= $id_venda ?></title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/meus_pedidos.php" class="btn-texto">← Meus pedidos</a></p>

    <div class="banner-hero">
        <h1>Pedido #<?= $id_venda ?></h1>
        <p>Feito em <?= htmlspecialchars(date('d/m/Y', strtotime($venda['data_venda']))) ?> às <?= htmlspecialchars(date('H:i', strtotime($venda['data_venda']))) ?></p>
    </div>

    <span class="status-pill<?= $classePill ? ' ' . $classePill : '' ?>" style="font-size:0.9rem; padding:8px 16px;"><?= htmlspecialchars($rotulo) ?></span>

    <?php if ($venda['status'] === 'Cancelado'): ?>
        <p class="alert alert-erro" style="margin-top:16px;">Item não liberado. Demora no pagamento. Se você já pagou, a loja entrará em contato pra resolver (reembolso ou reposição).</p>
    <?php elseif ($venda['status'] === 'Reservado'): ?>
        <p style="margin-top:16px;">Aguardando confirmação do pagamento...</p>
        <script>setTimeout(function () { window.location.reload(); }, 5000);</script>
    <?php endif; ?>

    <?php if (!empty($listaItens)): ?>
    <div class="layout-colunas" style="margin-top:20px;">
        <div>
            <h2>Itens</h2>
            <?php foreach ($listaItens as $it): ?>
            <div class="carrinho-item">
                <div class="foto">
                    <?php if ($it['foto']): ?>
                        <img src="/<?= htmlspecialchars(fotoComVersao($it['foto'])) ?>" alt="<?= htmlspecialchars($it['nome_produto']) ?>">
                    <?php else: ?>
                        <img src="/assets/img/produto-indisponivel.svg" alt="Produto indisponível">
                    <?php endif; ?>
                </div>
                <div class="info">
                    <div class="nome"><?= htmlspecialchars($it['nome_produto']) ?></div>
                    <?php if ($it['descricao_combinacao']): ?>
                        <div class="variacao"><?= htmlspecialchars($it['descricao_combinacao']) ?></div>
                    <?php endif; ?>
                    <div class="linha-controle">
                        <span><?= (int) $it['quantidade'] ?>x</span>
                        <span class="subtotal">R$ <?= number_format($it['subtotal'], 2, ',', '.') ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="painel-lateral">
            <div class="resumo-card">
                <div class="resumo-total" style="border-top:none; margin-top:0; padding-top:0;">
                    <span>Total</span>
                    <span>R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></span>
                </div>
                <?php if ($venda['entrega_nome']): ?>
                <p style="margin-top:12px; color:var(--cor-texto-suave);">Entrega: <?= htmlspecialchars($venda['entrega_nome']) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($dadosRetirada): ?>
            <div class="resumo-card">
                <h2>Retirar na loja</h2>
                <div class="info-lista">
                    <div>
                        <span class="rotulo">Endereço</span>
                        <span><?= htmlspecialchars($dadosRetirada['endereco_loja'] ?: 'A loja ainda não cadastrou o endereço — fale pelo WhatsApp abaixo.') ?></span>
                    </div>
                    <?php if ($dadosRetirada['whatsapp_loja']): ?>
                    <div>
                        <span class="rotulo">Telefone</span>
                        <span><?= htmlspecialchars(formatarWhatsappExibicao($dadosRetirada['whatsapp_loja'])) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($dadosRetirada['horario_atendimento']): ?>
                    <div>
                        <span class="rotulo">Horário</span>
                        <span><?= htmlspecialchars($dadosRetirada['horario_atendimento']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($listaRelacionados)): ?>
    <section class="secao-relacionados">
        <h2>Você também pode gostar</h2>
        <div class="product-grid">
            <?php foreach ($listaRelacionados as $rp): ?>
            <?php $fotosRp = $fotosRelacionados[(int) $rp['id_produto']] ?? []; ?>
            <a href="/loja/produto.php?id=<?= $rp['id_produto'] ?>" class="product-card">
                <div class="card-media">
                    <?php if (!empty($fotosRp)): ?>
                        <img src="/<?= htmlspecialchars(fotoComVersao($fotosRp[0])) ?>" alt="<?= htmlspecialchars($rp['nome']) ?>">
                    <?php endif; ?>
                </div>
                <div class="nome"><?= htmlspecialchars($rp['nome']) ?></div>
                <div class="price">R$ <?= number_format($rp['preco_base'], 2, ',', '.') ?></div>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
