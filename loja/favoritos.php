<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare(
    "SELECT f.id_favorito, p.id_produto, p.nome, p.preco_base, p.ativo,
            COALESCE(SUM(pv.estoque - pv.estoque_reservado), 0) AS disponivel,
            COALESCE(SUM(pv.estoque), 0) AS estoque_fisico,
            (SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = p.id_produto ORDER BY ordem LIMIT 1) AS foto
     FROM favoritos f
     JOIN produtos p ON p.id_produto = f.id_produto
     LEFT JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
     WHERE f.id_cliente = :ic
     GROUP BY f.id_favorito, p.id_produto, p.nome, p.preco_base, p.ativo
     ORDER BY f.criado_em DESC"
);
$stmt->execute([':ic' => $id_cliente]);
$favoritos = $stmt->fetchAll();

$agoraServidor = $pdo->query('SELECT NOW()')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Meus favoritos</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.3 8.9 1.5 5 5 4c2.4-.7 4.8.3 6.2 2.3L12 7.6l.8-1.3C14.2 4.3 16.6 3.3 19 4c3.5 1 4.7 4.9 3 7.9-2.5 4.5-10 9.1-10 9.1Z" fill="currentColor"/></svg></span>
        <h1>Meus favoritos</h1>
    </div>

    <?php if (empty($favoritos)): ?>
        <p>Você ainda não favoritou nenhum produto. Clique no ❤ de um produto no <a href="/loja/index.php">catálogo</a> pra guardar ele aqui.</p>
    <?php else: ?>
    <div class="product-grid" id="grade-favoritos">
        <?php foreach ($favoritos as $f): ?>
        <?php
            $disp = (int) $f['disponivel'];
            $ativo = (int) $f['ativo'] === 1;
            // Esgotado de verdade (não volta mais) é diferente de só estar preso no
            // carrinho de outro cliente (pode voltar a qualquer momento) — mesma
            // distinção já usada no catálogo e na página do produto.
            $reservado = $ativo && $disp <= 0 && (int) $f['estoque_fisico'] > 0;
            $indisponivel = !$ativo || (int) $f['estoque_fisico'] <= 0;
        ?>
        <a href="/loja/produto.php?id=<?= $f['id_produto'] ?>" class="product-card<?= $indisponivel ? ' indisponivel' : '' ?><?= $reservado ? ' reservado' : '' ?>" data-id-produto="<?= $f['id_produto'] ?>">
            <?php if ($reservado): ?><span class="tag-reservado">Em um carrinho</span><?php endif; ?>
            <div class="card-acoes">
                <button type="button" class="botao-acao favoritar ativo" data-id-produto="<?= $f['id_produto'] ?>" aria-label="Remover dos favoritos">
                    <svg class="icon-coracao" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.3 8.9 1.5 5 5 4c2.4-.7 4.8.3 6.2 2.3L12 7.6l.8-1.3C14.2 4.3 16.6 3.3 19 4c3.5 1 4.7 4.9 3 7.9-2.5 4.5-10 9.1-10 9.1Z"/></svg>
                </button>
            </div>
            <div class="card-media">
                <?php if ($f['foto']): ?><img src="/<?= htmlspecialchars($f['foto']) ?>" alt="<?= htmlspecialchars($f['nome']) ?>"><?php endif; ?>
            </div>
            <div class="nome"><?= htmlspecialchars($f['nome']) ?></div>
            <div class="price">R$ <?= number_format($f['preco_base'], 2, ',', '.') ?></div>
            <?php if ($indisponivel): ?>
                <div class="tag-indisponivel">Já vendido</div>
            <?php elseif ($reservado): ?>
                <div class="disponibilidade card disponibilidade-baixa">Aguardando pagamento de outro cliente</div>
            <?php else: ?>
                <div class="disponibilidade card<?= $disp <= 3 ? ' disponibilidade-baixa' : '' ?>"><?= $disp ?> disponíve<?= $disp === 1 ? 'l' : 'is' ?></div>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

<script>
document.getElementById('grade-favoritos')?.addEventListener('click', function (e) {
    const btn = e.target.closest('.favoritar');
    if (!btn) { return; }
    e.preventDefault();
    e.stopPropagation();
    fetch('/loja/ajax/favoritar.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'id_produto=' + btn.dataset.idProduto
    }).then(function (r) { return r.json(); }).then(function (data) {
        if (data.success && !data.favoritado) {
            btn.closest('.product-card').remove();
        }
    }).catch(function () {});
});

// Mesmo princípio do catálogo: reafirma o estado real de cada favorito a cada
// checagem, pra nunca ficar preso mostrando "em um carrinho" (ou o contrário)
// depois de já ter mudado. Esta página não mostra "Nova oportunidade" nem
// popup — só corrige a etiqueta de disponibilidade de cada cartão.
(function () {
    const grade = document.getElementById('grade-favoritos');
    if (!grade) { return; }
    let ultimaChecagem = <?= json_encode($agoraServidor) ?>;

    function sincronizarFavorito(card, disponivel) {
        if (card.classList.contains('indisponivel')) { return; }
        const reservado = disponivel <= 0;
        card.classList.toggle('reservado', reservado);

        let tag = card.querySelector('.tag-reservado');
        if (reservado && !tag) {
            tag = document.createElement('span');
            tag.className = 'tag-reservado';
            tag.textContent = 'Em um carrinho';
            card.prepend(tag);
        } else if (!reservado && tag) {
            tag.remove();
        }

        const dispEl = card.querySelector('.disponibilidade.card');
        if (dispEl) {
            if (reservado) {
                dispEl.className = 'disponibilidade card disponibilidade-baixa';
                dispEl.textContent = 'Aguardando pagamento de outro cliente';
            } else {
                dispEl.className = 'disponibilidade card' + (disponivel <= 3 ? ' disponibilidade-baixa' : '');
                dispEl.textContent = disponivel + (disponivel === 1 ? ' disponível' : ' disponíveis');
            }
        }
    }

    function verificarFavoritos() {
        const ids = Array.from(grade.querySelectorAll('[data-id-produto]')).map(function (el) { return el.dataset.idProduto; });
        if (ids.length === 0) { return; }
        const params = new URLSearchParams({ desde: ultimaChecagem, categoria: 0, ids: ids.join(',') });
        fetch('/loja/ajax/verificar_novidades.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { return; }
                ultimaChecagem = data.agora;
                (data.status || []).forEach(function (s) {
                    const card = grade.querySelector('[data-id-produto="' + s.id_produto + '"]');
                    if (card) { sincronizarFavorito(card, s.disponivel); }
                });
            })
            .catch(function () {});
    }

    setInterval(verificarFavoritos, 5000);
})();
</script>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
