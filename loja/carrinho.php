<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

liberarReservasExpiradas($pdo);

$id_cliente = (int) $_SESSION['id_cliente'];
$id_venda = buscarCarrinhoDoCliente($pdo, $id_cliente);

$itens = [];
$total = 0;
$dataVenda = null;
$segundosRestantes = 0;
$idsProdutos = [];

if ($id_venda) {
    $stmt = $pdo->prepare(
        "SELECT iv.id_item, iv.nome_produto, iv.descricao_combinacao, iv.quantidade, iv.preco_unit, iv.subtotal,
                iv.id_produto_variacao, pv.id_produto,
                (SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = pv.id_produto ORDER BY ordem LIMIT 1) AS foto,
                (pv.estoque - pv.estoque_reservado) AS disponivel_adicional
         FROM itens_venda iv
         LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
         WHERE iv.id_venda = :id
         ORDER BY iv.id_item"
    );
    $stmt->execute([':id' => $id_venda]);
    $itens = $stmt->fetchAll();

    foreach ($itens as $item) {
        if ($item['id_produto'] !== null) {
            $idsProdutos[(int) $item['id_produto']] = true;
        }
    }

    // Os segundos restantes são calculados no próprio MySQL, não em PHP: se o timezone do PHP
    // e o do MySQL divergirem (comum num WAMP padrão), comparar data_venda do banco com a hora
    // do PHP/navegador dá uma contagem errada — podendo zerar na hora e deixar a página num
    // laço infinito de reload.
    $stmtV = $pdo->prepare(
        "SELECT v.valor_total, v.data_venda,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(v.data_venda, INTERVAL cl.prazo_reserva_minutos MINUTE))) AS segundos_restantes
         FROM vendas v
         JOIN config_loja cl ON cl.id_config = 1
         WHERE v.id_venda = :id"
    );
    $stmtV->execute([':id' => $id_venda]);
    $venda = $stmtV->fetch();
    $total = (float) $venda['valor_total'];
    $dataVenda = $venda['data_venda'];
    $segundosRestantes = (int) $venda['segundos_restantes'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Carrinho</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/index.php" class="btn-texto">← Continuar comprando</a></p>
    <h1>Carrinho</h1>

    <?php if (empty($itens)): ?>
    <p>Seu carrinho está vazio. <a href="/loja/index.php">Ver catálogo</a></p>
    <?php else: ?>

    <?php if ($dataVenda): ?>
    <div class="timer-card" id="timer-card">
        <svg class="icon" style="width:1.6rem; height:1.6rem;" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm-1 3v6l5 3 1-1.6-4-2.4V7Z"/></svg>
        <span class="relogio" id="contagem">--:--</span>
        <span class="texto">Tempo pra pagar antes dos itens voltarem pro estoque</span>
    </div>
    <?php endif; ?>

    <div class="layout-colunas">
        <div>
            <?php foreach ($itens as $item): ?>
            <div class="carrinho-item" data-id-item="<?= $item['id_item'] ?>">
                <div class="foto">
                    <?php if ($item['foto']): ?>
                        <img src="/<?= htmlspecialchars($item['foto']) ?>" alt="<?= htmlspecialchars($item['nome_produto']) ?>">
                    <?php endif; ?>
                </div>
                <div class="info">
                    <div class="nome"><?= htmlspecialchars($item['nome_produto']) ?></div>
                    <?php if ($item['descricao_combinacao']): ?>
                        <div class="variacao"><?= htmlspecialchars($item['descricao_combinacao']) ?></div>
                    <?php endif; ?>
                    <div class="linha-controle">
                        <?php if ($item['id_produto_variacao'] !== null): ?>
                        <div class="stepper">
                            <button type="button" class="btn-diminuir" aria-label="Diminuir quantidade">−</button>
                            <span class="qtd"><?= (int) $item['quantidade'] ?></span>
                            <button type="button" class="btn-aumentar" aria-label="Aumentar quantidade" <?= (int) $item['disponivel_adicional'] <= 0 ? 'disabled' : '' ?>>+</button>
                        </div>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <span class="subtotal">R$ <span class="valor-subtotal"><?= number_format($item['subtotal'], 2, ',', '.') ?></span></span>
                    </div>
                    <button type="button" data-id-item="<?= $item['id_item'] ?>" class="btn-remover">Remover</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="painel-lateral">
            <div class="resumo-card">
                <div class="resumo-total" style="border-top:none; margin-top:0; padding-top:0;">
                    <span>Total</span>
                    <span id="valor-total">R$ <?= number_format($total, 2, ',', '.') ?></span>
                </div>
                <a href="/loja/checkout.php" class="btn btn-lg btn-bloco">Ir para o checkout</a>
            </div>
        </div>
    </div>

    <script>
    const idsProdutosNoCarrinho = <?= json_encode(array_keys($idsProdutos)) ?>;
    let restante = <?= $segundosRestantes ?>;

    function formatarTempo(segundos) {
        const min = Math.floor(segundos / 60);
        const seg = segundos % 60;
        return min + ':' + String(seg).padStart(2, '0');
    }

    function atualizarContagem() {
        const painel = document.getElementById('timer-card');
        const rotulo = document.getElementById('contagem');
        if (!rotulo) { return; }
        rotulo.textContent = formatarTempo(restante);
        if (painel) { painel.classList.toggle('urgente', restante <= 60); }

        if (restante <= 0) {
            clearInterval(intervaloContagem);
            const ids = idsProdutosNoCarrinho.join(',');
            window.location.href = '/loja/index.php' + (ids ? '?voltou=' + ids : '');
            return;
        }
        restante--;
    }

    let intervaloContagem = null;
    if (document.getElementById('contagem')) {
        atualizarContagem();
        intervaloContagem = setInterval(atualizarContagem, 1000);
    }

    document.querySelectorAll('.btn-remover').forEach(function (btn) {
        btn.addEventListener('click', function () {
            fetch('/loja/ajax/remover_item.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_item=' + btn.dataset.idItem
            }).then(r => r.json()).then(data => {
                if (data.success) { window.location.reload(); } else { alert(data.message); }
            }).catch(function () {
                alert('Erro de conexão. Tente novamente.');
            });
        });
    });

    function alterarQuantidade(linha, delta) {
        const idItem = linha.dataset.idItem;
        fetch('/loja/ajax/alterar_quantidade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_item=' + idItem + '&delta=' + delta
        }).then(r => r.json()).then(data => {
            if (!data.success) { alert(data.message); return; }
            window.location.reload();
        }).catch(function () {
            alert('Erro de conexão. Tente novamente.');
        });
    }

    document.querySelectorAll('.btn-aumentar').forEach(function (btn) {
        btn.addEventListener('click', function () {
            alterarQuantidade(btn.closest('.carrinho-item'), 1);
        });
    });
    document.querySelectorAll('.btn-diminuir').forEach(function (btn) {
        btn.addEventListener('click', function () {
            alterarQuantidade(btn.closest('.carrinho-item'), -1);
        });
    });
    </script>
    <?php endif; ?>
</main>
</body>
</html>
