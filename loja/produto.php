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
     HAVING disponivel > 0
     ORDER BY descricao_combinacao'
);
$combinacoes->execute([':id' => $id_produto]);
$listaCombinacoes = $combinacoes->fetchAll();

$disponivelTotal = array_sum(array_column($listaCombinacoes, 'disponivel'));

// Relacionados: prioriza a mesma categoria; se não achar nenhum (categoria pequena
// ou só este produto nela), cai pra qualquer outro produto ativo com estoque.
$relacionados = $pdo->prepare(
    "SELECT DISTINCT p2.id_produto, p2.nome, p2.preco_base
     FROM produtos p2
     JOIN produto_variacoes pv2 ON pv2.id_produto = p2.id_produto
     WHERE p2.ativo = 1 AND p2.id_produto != :id AND p2.id_categoria = :cat
       AND (pv2.estoque - pv2.estoque_reservado) > 0
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
           AND (pv2.estoque - pv2.estoque_reservado) > 0
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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= htmlspecialchars($produto['nome']) ?></title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <p><a href="/loja/index.php" class="btn-texto">← Voltar ao catálogo</a></p>

    <div class="produto-layout">
    <?php if (count($listaFotos) > 1): ?>
    <div class="produto-carousel carousel" data-carousel>
        <div class="carousel-track">
            <?php foreach ($listaFotos as $foto): ?>
                <img src="/<?= htmlspecialchars($foto) ?>" alt="<?= htmlspecialchars($produto['nome']) ?>">
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
        <img src="/<?= htmlspecialchars($listaFotos[0]) ?>" alt="<?= htmlspecialchars($produto['nome']) ?>" style="width:100%; height:100%; object-fit:cover;">
    </div>
    <?php else: ?>
    <div class="produto-carousel"></div>
    <?php endif; ?>

    <div>
    <span class="produto-categoria"><?= htmlspecialchars($produto['categoria']) ?></span>
    <h1><?= htmlspecialchars($produto['nome']) ?></h1>
    <p><?= nl2br(htmlspecialchars($produto['descricao'] ?? '')) ?></p>
    <p class="produto-preco">R$ <?= number_format($produto['preco_base'], 2, ',', '.') ?></p>
    <?php if ($disponivelTotal > 0): ?>
    <p class="disponibilidade<?= $disponivelTotal <= 3 ? ' disponibilidade-baixa' : '' ?>"><?= $disponivelTotal ?> <?= $disponivelTotal === 1 ? 'unidade disponível' : 'unidades disponíveis' ?></p>
    <?php endif; ?>

    <?php if (empty($_SESSION['id_cliente'])): ?>
        <a href="/loja/cadastro.php" class="btn btn-lg btn-bloco">Entrar ou cadastrar pra comprar</a>
    <?php elseif (empty($listaCombinacoes)): ?>
        <p class="alert alert-erro">Sem estoque disponível no momento.</p>
    <?php else: ?>
    <form id="form-adicionar">
        <label>Opção
            <select name="id_produto_variacao" id="id_produto_variacao">
                <?php foreach ($listaCombinacoes as $c): ?>
                <option value="<?= $c['id_produto_variacao'] ?>">
                    <?= htmlspecialchars($c['descricao_combinacao'] ?: 'Padrão') ?> — R$ <?= number_format($c['preco'], 2, ',', '.') ?> (<?= (int) $c['disponivel'] ?> disponível)
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
                        <img src="/<?= htmlspecialchars($fotosRp[0]) ?>" alt="<?= htmlspecialchars($rp['nome']) ?>">
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
