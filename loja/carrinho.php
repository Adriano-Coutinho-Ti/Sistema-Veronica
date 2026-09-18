<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';
exigirClienteLogado();

liberarReservasExpiradas($pdo);

$id_cliente = (int) $_SESSION['id_cliente'];
$whatsappLoginHabilitado = (bool) $pdo->query('SELECT whatsapp_verificacao_ativo FROM config_dev WHERE id_config = 1')->fetchColumn();
$emailVerificado = clienteEmailVerificado($pdo, $id_cliente);
$whatsappVerificado = $whatsappLoginHabilitado && clienteWhatsappVerificado($pdo, $id_cliente);
$verificado = $emailVerificado || $whatsappVerificado;
$id_venda = $verificado ? buscarCarrinhoDoCliente($pdo, $id_cliente) : null;

$itens = [];
$total = 0;
$dataVenda = null;
$segundosRestantes = 0;
$idsProdutos = [];

if ($id_venda) {
    $stmt = $pdo->prepare(
        "SELECT iv.id_item, iv.nome_produto, iv.descricao_combinacao, iv.quantidade, iv.preco_unit, iv.subtotal,
                iv.id_produto_variacao, pv.id_produto, COALESCE(p.estoque_gerenciado, 1) AS estoque_gerenciado,
                (SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = pv.id_produto ORDER BY ordem LIMIT 1) AS foto,
                (pv.estoque - pv.estoque_reservado) AS disponivel_adicional
         FROM itens_venda iv
         LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
         LEFT JOIN produtos p ON p.id_produto = pv.id_produto
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
        "SELECT v.valor_total, v.data_venda, cl.prazo_reserva_minutos,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(v.data_venda, INTERVAL cl.prazo_reserva_minutos MINUTE))) AS segundos_restantes
         FROM vendas v
         JOIN config_loja cl ON cl.id_config = 1
         WHERE v.id_venda = :id"
    );
    $stmtV->execute([':id' => $id_venda]);
    $venda = $stmtV->fetch();
    $total = (float) $venda['valor_total'];
    // prazo_reserva_minutos = 0 é o "carrinho livre" — sem cronômetro, sem prazo
    // nenhum. Sem isso a conta acima daria 0 segundos restantes (data_venda +
    // 0 minutos já passou) e a página achava que o tempo tinha acabado na hora,
    // redirecionando o cliente pro catálogo assim que abrisse o carrinho.
    $carrinhoLivre = (int) $venda['prazo_reserva_minutos'] === 0;
    $dataVenda = $carrinhoLivre ? null : $venda['data_venda'];
    $segundosRestantes = (int) $venda['segundos_restantes'];
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Carrinho</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/index.php" class="btn-texto">← Continuar comprando</a></p>
    <div class="banner-hero">
        <h1>Carrinho</h1>
        <p>Confira os itens antes de finalizar sua compra.</p>
    </div>

    <?php if (!$verificado): ?>
    <div class="resumo-card" id="bloqueio-verificacao" style="max-width:460px; margin:0 auto; text-align:center;">
        <h2>Confirme seu e-mail<?= $whatsappLoginHabilitado ? ' ou WhatsApp' : '' ?> pra continuar</h2>
        <p style="color:var(--cor-texto-suave); margin-bottom:18px;">Por segurança, o carrinho só libera depois que você confirma <?= $whatsappLoginHabilitado ? 'um dos dois' : 'seu e-mail' ?> — enviamos um código de 6 dígitos assim que você se cadastrou.</p>
        <button type="button" class="btn btn-bloco btn-abrir-validar-email">Validar e-mail</button>
        <?php if ($whatsappLoginHabilitado): ?>
        <button type="button" class="btn-outline btn-bloco btn-abrir-validar-whatsapp" style="margin-top:10px;">Validar WhatsApp</button>
        <?php endif; ?>
    </div>
    <?php elseif (empty($itens)): ?>
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
                        <img src="/<?= htmlspecialchars(fotoComVersao($item['foto'])) ?>" alt="<?= htmlspecialchars($item['nome_produto']) ?>">
                    <?php endif; ?>
                </div>
                <div class="info">
                    <div class="nome"><?= htmlspecialchars($item['nome_produto']) ?></div>
                    <?php if ($item['descricao_combinacao']): ?>
                        <div class="variacao"><?= htmlspecialchars($item['descricao_combinacao']) ?></div>
                    <?php endif; ?>
                    <?php
                        $semControleEstoqueItem = $item['id_produto_variacao'] !== null && !(int) $item['estoque_gerenciado'];
                        $totalDisponivel = (int) $item['disponivel_adicional'] + (int) $item['quantidade'];
                    ?>
                    <?php if ($item['id_produto_variacao'] !== null && !$semControleEstoqueItem): ?>
                        <div class="disponibilidade<?= $totalDisponivel <= 3 ? ' disponibilidade-baixa' : '' ?>"><?= $totalDisponivel ?> disponíve<?= $totalDisponivel === 1 ? 'l' : 'is' ?> no total</div>
                    <?php endif; ?>
                    <div class="linha-controle">
                        <?php if ($item['id_produto_variacao'] !== null): ?>
                        <div class="stepper">
                            <button type="button" class="btn-diminuir" aria-label="Diminuir quantidade">−</button>
                            <span class="qtd"><?= (int) $item['quantidade'] ?></span>
                            <button type="button" class="btn-aumentar" aria-label="Aumentar quantidade" <?= (!$semControleEstoqueItem && (int) $item['disponivel_adicional'] <= 0) ? 'disabled' : '' ?>>+</button>
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
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
