<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

liberarReservasExpiradas($pdo);

$id_produto = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT p.*, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.id_produto = :id AND p.ativo = 1');
$stmt->execute([':id' => $id_produto]);
$produto = $stmt->fetch();

if (!$produto) {
    http_response_code(404);
    echo 'Produto não encontrado.';
    exit;
}

$fotos = $pdo->prepare('SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = :id ORDER BY ordem');
$fotos->execute([':id' => $id_produto]);
$listaFotos = $fotos->fetchAll(PDO::FETCH_COLUMN);

$semControleEstoque = !(int) $produto['estoque_gerenciado'];

$combinacoes = $pdo->prepare(
    'SELECT pv.id_produto_variacao, COALESCE(pv.preco, p.preco_base) AS preco,
            (pv.estoque - pv.estoque_reservado) AS disponivel,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao
     FROM produto_variacoes pv
     JOIN produtos p ON p.id_produto = pv.id_produto
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE pv.id_produto = :id
     GROUP BY pv.id_produto_variacao, pv.preco, p.preco_base, pv.estoque, pv.estoque_reservado
     HAVING p.estoque_gerenciado = 0 OR disponivel > 0
     ORDER BY descricao_combinacao'
);
$combinacoes->execute([':id' => $id_produto]);
$listaCombinacoes = $combinacoes->fetchAll();

$disponivelTotal = array_sum(array_column($listaCombinacoes, 'disponivel'));

// Estoque físico (sem descontar reserva) diz se o produto só está temporariamente
// preso no carrinho de outro cliente (pode voltar) ou se já foi vendido de verdade
// (não volta mais). Só importa quando não há nenhuma combinação disponível agora.
$stmtEstoqueFisico = $pdo->prepare('SELECT COALESCE(SUM(estoque), 0) FROM produto_variacoes WHERE id_produto = :id');
$stmtEstoqueFisico->execute([':id' => $id_produto]);
$estoqueFisicoTotal = (int) $stmtEstoqueFisico->fetchColumn();
$reservadoEmCarrinho = !$semControleEstoque && $disponivelTotal <= 0 && $estoqueFisicoTotal > 0;

$urlProdutoAbsoluta = 'https://brechodaveve.codernex.com.br/loja/produto.php?id=' . $id_produto;
// og:image de propósito NÃO leva o "?v=..." do cache-busting: quem lê essa
// tag é o rastreador do WhatsApp/Facebook, não o navegador de quem visita —
// o cache do lado deles é resolvido pela ferramenta de "Scrape Again" deles
// mesmos (developers.facebook.com/tools/debug/), nunca por variar a URL.
// Adicionar query string aqui só arrisca compatibilidade com o rastreador
// sem resolver problema nenhum de cache no nosso lado.
$fotoOgAbsoluta = !empty($listaFotos) ? 'https://brechodaveve.codernex.com.br/' . $listaFotos[0] : null;
$linkCompartilharWhatsapp = montarLinkCompartilharWhatsapp($produto['nome'], (float) $produto['preco_base'], $urlProdutoAbsoluta);

$ehFavorito = false;
if (!empty($_SESSION['id_cliente'])) {
    $stmtFav = $pdo->prepare('SELECT 1 FROM favoritos WHERE id_cliente = :ic AND id_produto = :ip');
    $stmtFav->execute([':ic' => (int) $_SESSION['id_cliente'], ':ip' => $id_produto]);
    $ehFavorito = (bool) $stmtFav->fetchColumn();
}

// Relacionados: prioriza a mesma categoria; se não achar nenhum (categoria pequena
// ou só este produto nela), cai pra qualquer outro produto ativo com estoque.
$relacionados = $pdo->prepare(
    "SELECT DISTINCT p2.id_produto, p2.nome, p2.preco_base
     FROM produtos p2
     JOIN produto_variacoes pv2 ON pv2.id_produto = p2.id_produto
     WHERE p2.ativo = 1 AND p2.id_produto != :id AND p2.id_categoria = :cat
       AND (p2.estoque_gerenciado = 0 OR (pv2.estoque - pv2.estoque_reservado) > 0)
     ORDER BY RAND() LIMIT 8"
);
$relacionados->execute([':id' => $id_produto, ':cat' => $produto['id_categoria']]);
$listaRelacionados = $relacionados->fetchAll();

if (empty($listaRelacionados)) {
    $outros = $pdo->prepare(
        "SELECT DISTINCT p2.id_produto, p2.nome, p2.preco_base
         FROM produtos p2
         JOIN produto_variacoes pv2 ON pv2.id_produto = p2.id_produto
         WHERE p2.ativo = 1 AND p2.id_produto != :id
           AND (p2.estoque_gerenciado = 0 OR (pv2.estoque - pv2.estoque_reservado) > 0)
         ORDER BY RAND() LIMIT 8"
    );
    $outros->execute([':id' => $id_produto]);
    $listaRelacionados = $outros->fetchAll();
}

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
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($produto['nome']) ?></title>
<meta property="og:type" content="product">
<meta property="og:title" content="<?= htmlspecialchars($produto['nome']) ?>">
<meta property="og:description" content="R$ <?= number_format($produto['preco_base'], 2, ',', '.') ?> — confira na Brechó da Veve">
<?php if ($fotoOgAbsoluta): ?><meta property="og:image" content="<?= htmlspecialchars($fotoOgAbsoluta) ?>"><?php endif; ?>
<meta property="og:url" content="<?= htmlspecialchars($urlProdutoAbsoluta) ?>">
<meta name="twitter:card" content="summary_large_image">
</head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/index.php" class="btn-texto">← Voltar ao catálogo</a></p>

    <div class="produto-layout">
    <?php if (count($listaFotos) > 1): ?>
    <div class="produto-carousel carousel" data-carousel>
        <div class="carousel-track">
            <?php foreach ($listaFotos as $foto): ?>
                <img src="/<?= htmlspecialchars(fotoComVersao($foto)) ?>" alt="<?= htmlspecialchars($produto['nome']) ?>">
            <?php endforeach; ?>
        </div>
        <div class="carousel-dots">
            <?php foreach ($listaFotos as $i => $foto): ?>
                <button type="button" class="dot<?= $i === 0 ? ' ativo' : '' ?>" data-index="<?= $i ?>" aria-label="Foto <?= $i + 1 ?>"></button>
            <?php endforeach; ?>
        </div>
        <button type="button" class="carousel-prev" aria-label="Foto anterior">‹</button>
        <button type="button" class="carousel-next" aria-label="Próxima foto">›</button>
    </div>
    <?php elseif (count($listaFotos) === 1): ?>
    <div class="produto-carousel">
        <img src="/<?= htmlspecialchars(fotoComVersao($listaFotos[0])) ?>" alt="<?= htmlspecialchars($produto['nome']) ?>" style="width:100%; height:100%; object-fit:cover;">
    </div>
    <?php else: ?>
    <div class="produto-carousel"></div>
    <?php endif; ?>

    <div>
    <span class="produto-categoria"><?= htmlspecialchars($produto['categoria']) ?></span>
    <h1><?= htmlspecialchars($produto['nome']) ?></h1>
    <p><?= nl2br(htmlspecialchars($produto['descricao'] ?? '')) ?></p>
    <p class="produto-preco">R$ <?= number_format($produto['preco_base'], 2, ',', '.') ?></p>
    <div style="display:flex; gap:10px; align-items:center; margin:4px 0 14px; flex-wrap:wrap;">
        <a href="<?= htmlspecialchars($linkCompartilharWhatsapp) ?>" target="_blank" rel="noopener" class="btn-compartilhar-whatsapp" style="margin:0;">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
            Compartilhar no WhatsApp
        </a>
        <?php if (!empty($_SESSION['id_cliente'])): ?>
        <button type="button" id="btn-favoritar" class="botao-acao favoritar<?= $ehFavorito ? ' ativo' : '' ?>" data-id-produto="<?= $id_produto ?>" aria-label="<?= $ehFavorito ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>">
            <svg class="icon-coracao" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.3 8.9 1.5 5 5 4c2.4-.7 4.8.3 6.2 2.3L12 7.6l.8-1.3C14.2 4.3 16.6 3.3 19 4c3.5 1 4.7 4.9 3 7.9-2.5 4.5-10 9.1-10 9.1Z"/></svg>
        </button>
        <script>
        document.getElementById('btn-favoritar').addEventListener('click', function () {
            fetch('/loja/ajax/favoritar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_produto=' + this.dataset.idProduto
            }).then(r => r.json()).then(data => {
                if (!data.success) { return; }
                this.classList.toggle('ativo', data.favoritado);
                this.setAttribute('aria-label', data.favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos');
            });
        });
        </script>
        <?php endif; ?>
    </div>
    <?php if (!$semControleEstoque && $disponivelTotal > 0): ?>
    <p class="disponibilidade<?= $disponivelTotal <= 3 ? ' disponibilidade-baixa' : '' ?>"><?= $disponivelTotal ?> <?= $disponivelTotal === 1 ? 'unidade disponível' : 'unidades disponíveis' ?></p>
    <?php endif; ?>

    <?php if ($reservadoEmCarrinho): ?>
        <p class="alert alert-info">Este item está no carrinho de outro cliente e ainda não foi comprado. Ele pode voltar a ficar disponível a qualquer momento — vale a pena checar de novo daqui a pouco.</p>
    <?php elseif (!$semControleEstoque && empty($listaCombinacoes)): ?>
        <p class="alert alert-erro">Sem estoque disponível no momento.</p>
    <?php endif; ?>

    <?php if (empty($_SESSION['id_cliente'])): ?>
        <a href="/loja/cadastro.php" class="btn btn-lg btn-bloco">Entrar ou cadastrar pra comprar</a>
    <?php elseif (empty($listaCombinacoes)): ?>
        <?php // Mensagem de indisponibilidade já mostrada acima. ?>
    <?php else: ?>
    <form id="form-adicionar">
        <label>Opção
            <select name="id_produto_variacao" id="id_produto_variacao">
                <?php foreach ($listaCombinacoes as $c): ?>
                <option value="<?= $c['id_produto_variacao'] ?>">
                    <?= htmlspecialchars($c['descricao_combinacao'] ?: 'Padrão') ?> — R$ <?= number_format($c['preco'], 2, ',', '.') ?><?= $semControleEstoque ? '' : ' (' . (int) $c['disponivel'] . ' disponível)' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="btn-lg btn-bloco">Adicionar ao carrinho</button>
    </form>
    <p id="mensagem-adicionar"></p>
    <script>
    document.getElementById('form-adicionar').addEventListener('submit', function (e) {
        e.preventDefault();
        const idProdutoVariacao = document.getElementById('id_produto_variacao').value;
        fetch('/loja/ajax/adicionar_item.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'id_produto_variacao=' + idProdutoVariacao + '&quantidade=1'
        }).then(r => r.json()).then(data => {
            if (data.success) {
                window.location.href = '/loja/carrinho.php';
            } else {
                document.getElementById('mensagem-adicionar').textContent = data.message;
            }
        }).catch(err => {
            document.getElementById('mensagem-adicionar').textContent = 'Erro de conexão. Tente novamente.';
        });
    });
    </script>
    <?php endif; ?>
    </div>
    </div>

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
<script>
// Essa página não tem como reconstruir sozinha, em JS, tudo que muda quando a
// disponibilidade vira (combinações, preço por opção, formulário) — em vez de
// tentar remendar o DOM e arriscar mostrar algo errado, ela só checa a cada
// poucos segundos se o produto cruzou de "disponível" pra "reservado/esgotado"
// (ou o contrário) e, se cruzou, recarrega do zero. Garante que a página nunca
// fica presa mostrando um estado que já não é mais verdade.
(function () {
    const idProduto = <?= (int) $id_produto ?>;
    let ultimaChecagem = <?= json_encode($pdo->query('SELECT NOW()')->fetchColumn()) ?>;
    let estavaDisponivel = <?= json_encode($disponivelTotal > 0) ?>;

    function checarDisponibilidade() {
        const params = new URLSearchParams({ desde: ultimaChecagem, categoria: 0, ids: String(idProduto) });
        fetch('/loja/ajax/verificar_novidades.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { return; }
                ultimaChecagem = data.agora;
                const status = (data.status || []).find(function (s) { return s.id_produto === idProduto; });
                if (!status || status.estoque_gerenciado === 0) { return; }
                const agoraDisponivel = status.disponivel > 0;
                if (agoraDisponivel !== estavaDisponivel) {
                    window.location.reload();
                }
            })
            .catch(function () {});
    }

    setInterval(checarDisponibilidade, 5000);
})();
</script>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
