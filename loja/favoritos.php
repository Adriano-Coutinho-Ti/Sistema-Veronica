<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];

const FAVORITOS_POR_PAGINA = 12;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$stmtTotalFav = $pdo->prepare('SELECT COUNT(*) FROM favoritos WHERE id_cliente = :ic');
$stmtTotalFav->execute([':ic' => $id_cliente]);
$totalFavoritos = (int) $stmtTotalFav->fetchColumn();
$totalPaginasFav = max(1, (int) ceil($totalFavoritos / FAVORITOS_POR_PAGINA));
$pagina = min($pagina, $totalPaginasFav);
$offsetFav = ($pagina - 1) * FAVORITOS_POR_PAGINA;

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
     ORDER BY f.criado_em DESC
     LIMIT :limite OFFSET :offset"
);
$stmt->bindValue(':ic', $id_cliente, PDO::PARAM_INT);
$stmt->bindValue(':limite', FAVORITOS_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offsetFav, PDO::PARAM_INT);
$stmt->execute();
$favoritos = $stmt->fetchAll();

// "Você também pode gostar" — mesma seção da página de produto, mostrando
// outros produtos ativos com estoque, sorteados, excluindo o que já está
// nos favoritos do cliente. Usa TODOS os favoritos do cliente (não só os
// desta página), senão um item favoritado que caiu noutra página podia
// aparecer aqui como "sugestão".
$stmtIdsFav = $pdo->prepare('SELECT id_produto FROM favoritos WHERE id_cliente = :ic');
$stmtIdsFav->execute([':ic' => $id_cliente]);
$idsFavoritados = $stmtIdsFav->fetchAll(PDO::FETCH_COLUMN);
$placeholdersExcluir = !empty($idsFavoritados) ? implode(',', array_fill(0, count($idsFavoritados), '?')) : null;
$sqlRelacionados = "SELECT DISTINCT p2.id_produto, p2.nome, p2.preco_base
     FROM produtos p2
     JOIN produto_variacoes pv2 ON pv2.id_produto = p2.id_produto
     WHERE p2.ativo = 1 AND (pv2.estoque - pv2.estoque_reservado) > 0"
     . ($placeholdersExcluir ? " AND p2.id_produto NOT IN ($placeholdersExcluir)" : '')
     . ' ORDER BY RAND() LIMIT 8';
$stmtRelacionados = $pdo->prepare($sqlRelacionados);
$stmtRelacionados->execute(array_values($idsFavoritados));
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

$agoraServidor = $pdo->query('SELECT NOW()')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Meus favoritos</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="banner-hero">
        <h1>Meus favoritos</h1>
        <p>Os itens que você mais amou, guardados aqui num só lugar.</p>
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
                <?php if ($f['foto']): ?><img src="/<?= htmlspecialchars(fotoComVersao($f['foto'])) ?>" alt="<?= htmlspecialchars($f['nome']) ?>"><?php endif; ?>
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

    <?php if ($totalPaginasFav > 1): ?>
    <nav class="paginacao">
        <?php if ($pagina > 1): ?><a href="/loja/favoritos.php?pagina=<?= $pagina - 1 ?>">‹ Anterior</a><?php endif; ?>
        <?php for ($p = 1; $p <= $totalPaginasFav; $p++): ?>
            <a href="/loja/favoritos.php?pagina=<?= $p ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <?php if ($pagina < $totalPaginasFav): ?><a href="/loja/favoritos.php?pagina=<?= $pagina + 1 ?>">Próxima ›</a><?php endif; ?>
    </nav>
    <?php endif; ?>
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
