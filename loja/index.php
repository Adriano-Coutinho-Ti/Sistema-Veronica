<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

liberarReservasExpiradas($pdo);

$id_categoria = (int) ($_GET['categoria'] ?? 0);

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();

$sql = "SELECT DISTINCT p.id_produto, p.nome, p.preco_base
        FROM produtos p
        JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE p.ativo = 1 AND (pv.estoque - pv.estoque_reservado) > 0";
$params = [];
if ($id_categoria > 0) {
    $sql .= ' AND p.id_categoria = :ic';
    $params[':ic'] = $id_categoria;
}
$sql .= ' ORDER BY p.nome';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produtos = $stmt->fetchAll();

$fotosPorProduto = [];
if (!empty($produtos)) {
    $ids = array_column($produtos, 'id_produto');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtFotos = $pdo->prepare(
        "SELECT id_produto, caminho_arquivo FROM produto_fotos WHERE id_produto IN ($placeholders) ORDER BY id_produto, ordem"
    );
    $stmtFotos->execute($ids);
    foreach ($stmtFotos->fetchAll() as $f) {
        $fotosPorProduto[(int) $f['id_produto']][] = $f['caminho_arquivo'];
    }
}

// IDs vindos do redirecionamento do carrinho quando o cronômetro zera — ficam
// destacados como "nova oportunidade" pra quem chegou aqui (e pra qualquer um
// que reabra esse link).
$voltouIds = [];
if (!empty($_GET['voltou'])) {
    $voltouIds = array_filter(array_map('intval', explode(',', $_GET['voltou'])));
}

// Instante de referência pro poll de novidades (loja/ajax/verificar_novidades.php) —
// vem do MySQL, não do PHP, pra bater com o mesmo relógio usado em liberado_em.
$agoraServidor = $pdo->query('SELECT NOW()')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Loja</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Catálogo</h1>

    <div id="banner-oportunidade">
        <?php if (!empty($voltouIds)): ?>
        <p class="alert alert-oportunidade"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm-1 3v6l5 3 1-1.6-4-2.4V7Z"/></svg> Seu tempo pra pagar acabou — os itens do seu carrinho voltaram pra loja e já estão disponíveis de novo (inclusive pra outros clientes).</p>
        <?php endif; ?>
    </div>

    <div class="catalogo-layout">
        <aside class="categorias-lateral">
            <h2>Categorias</h2>
            <nav>
                <a href="/loja/index.php" class="<?= $id_categoria === 0 ? 'ativa' : '' ?>">Todas</a>
                <?php foreach ($categorias as $c): ?>
                    <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>" class="<?= $id_categoria === (int) $c['id_categoria'] ? 'ativa' : '' ?>">
                        <?= htmlspecialchars($c['nome']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div>
            <div class="categorias">
                <a href="/loja/index.php" class="categoria-pill<?= $id_categoria === 0 ? ' ativa' : '' ?>">Todas</a>
                <?php foreach ($categorias as $c): ?>
                    <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>" class="categoria-pill<?= $id_categoria === (int) $c['id_categoria'] ? ' ativa' : '' ?>">
                        <?= htmlspecialchars($c['nome']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="product-grid" id="grade-produtos">
                <?php foreach ($produtos as $p): ?>
                <?php
                    $fotos = $fotosPorProduto[(int) $p['id_produto']] ?? [];
                    $ehOportunidade = in_array((int) $p['id_produto'], $voltouIds, true);
                ?>
                <a href="/loja/produto.php?id=<?= $p['id_produto'] ?>" class="product-card<?= $ehOportunidade ? ' voltou' : '' ?>" data-id-produto="<?= $p['id_produto'] ?>">
                    <?php if ($ehOportunidade): ?><span class="tag-oportunidade"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg> Nova oportunidade</span><?php endif; ?>
                    <div class="card-media">
                        <?php if (count($fotos) > 1): ?>
                        <div class="carousel" data-carousel data-carousel-auto="2000">
                            <div class="carousel-track">
                                <?php foreach ($fotos as $foto): ?>
                                    <img src="/<?= htmlspecialchars($foto) ?>" alt="<?= htmlspecialchars($p['nome']) ?>">
                                <?php endforeach; ?>
                            </div>
                            <div class="carousel-dots">
                                <?php foreach ($fotos as $i => $foto): ?>
                                    <button type="button" class="dot<?= $i === 0 ? ' ativo' : '' ?>" data-index="<?= $i ?>" aria-label="Foto <?= $i + 1 ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php elseif (count($fotos) === 1): ?>
                            <img src="/<?= htmlspecialchars($fotos[0]) ?>" alt="<?= htmlspecialchars($p['nome']) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="nome"><?= htmlspecialchars($p['nome']) ?></div>
                    <div class="price">R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($produtos)): ?>
                    <p>Nenhum produto disponível nessa categoria no momento.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

<script>
(function () {
    let ultimaChecagem = <?= json_encode($agoraServidor) ?>;
    const categoriaAtual = <?= (int) $id_categoria ?>;
    let avisoTimeout = null;

    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    function criarCardProduto(produto) {
        const precoFormatado = produto.preco_base.toFixed(2).replace('.', ',');
        let mediaHtml = '';
        if (produto.fotos.length > 1) {
            const imgs = produto.fotos.map(function (f) { return '<img src="/' + escaparHtml(f) + '" alt="' + escaparHtml(produto.nome) + '">'; }).join('');
            const dots = produto.fotos.map(function (f, i) { return '<button type="button" class="dot' + (i === 0 ? ' ativo' : '') + '" data-index="' + i + '"></button>'; }).join('');
            mediaHtml = '<div class="carousel" data-carousel data-carousel-auto="2000"><div class="carousel-track">' + imgs + '</div><div class="carousel-dots">' + dots + '</div></div>';
        } else if (produto.fotos.length === 1) {
            mediaHtml = '<img src="/' + escaparHtml(produto.fotos[0]) + '" alt="' + escaparHtml(produto.nome) + '">';
        }

        const a = document.createElement('a');
        a.href = '/loja/produto.php?id=' + produto.id_produto;
        a.className = 'product-card voltou';
        a.dataset.idProduto = String(produto.id_produto);
        a.innerHTML =
            '<span class="tag-oportunidade"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg> Nova oportunidade</span>' +
            '<div class="card-media">' + mediaHtml + '</div>' +
            '<div class="nome">' + escaparHtml(produto.nome) + '</div>' +
            '<div class="price">R$ ' + precoFormatado + '</div>';
        return a;
    }

    function mostrarAviso(qtd) {
        const banner = document.getElementById('banner-oportunidade');
        const sparkle = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg>';
        const texto = qtd === 1
            ? 'Um item que estava no carrinho de alguém acabou de voltar — já está disponível!'
            : qtd + ' itens que estavam em carrinhos acabaram de voltar — já estão disponíveis!';
        banner.innerHTML = '<p class="alert alert-oportunidade">' + sparkle + ' ' + texto + '</p>';
        clearTimeout(avisoTimeout);
        avisoTimeout = setTimeout(function () { banner.innerHTML = ''; }, 9000);
    }

    function verificarNovidades() {
        const params = new URLSearchParams({ desde: ultimaChecagem, categoria: categoriaAtual });
        fetch('/loja/ajax/verificar_novidades.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { return; }
                ultimaChecagem = data.agora;
                if (data.produtos.length === 0) { return; }

                const grade = document.getElementById('grade-produtos');
                data.produtos.forEach(function (produto) {
                    const existente = grade.querySelector('[data-id-produto="' + produto.id_produto + '"]');
                    if (existente) {
                        existente.classList.add('voltou');
                        if (!existente.querySelector('.tag-oportunidade')) {
                            const tag = document.createElement('span');
                            tag.className = 'tag-oportunidade';
                            tag.textContent = 'Nova oportunidade';
                            existente.prepend(tag);
                        }
                    } else {
                        const novoCard = criarCardProduto(produto);
                        grade.prepend(novoCard);
                        iniciarCarrosseis(novoCard);
                    }
                });

                mostrarAviso(data.produtos.length);
            })
            .catch(function () {});
    }

    setInterval(verificarNovidades, 5000);
})();
</script>
</main>
</body>
</html>
