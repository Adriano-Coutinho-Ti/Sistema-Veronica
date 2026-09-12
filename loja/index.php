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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Loja</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Catálogo</h1>

    <?php if (!empty($voltouIds)): ?>
    <p class="alert alert-oportunidade">⏰ Seu tempo pra pagar acabou — os itens do seu carrinho voltaram pra loja e já estão disponíveis de novo (inclusive pra outros clientes).</p>
    <?php endif; ?>

    <div class="categorias">
        <a href="/loja/index.php" class="categoria-pill<?= $id_categoria === 0 ? ' ativa' : '' ?>">Todas</a>
        <?php foreach ($categorias as $c): ?>
            <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>" class="categoria-pill<?= $id_categoria === (int) $c['id_categoria'] ? ' ativa' : '' ?>">
                <?= htmlspecialchars($c['nome']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="product-grid">
        <?php foreach ($produtos as $p): ?>
        <?php
            $fotos = $fotosPorProduto[(int) $p['id_produto']] ?? [];
            $ehOportunidade = in_array((int) $p['id_produto'], $voltouIds, true);
        ?>
        <a href="/loja/produto.php?id=<?= $p['id_produto'] ?>" class="product-card<?= $ehOportunidade ? ' voltou' : '' ?>">
            <?php if ($ehOportunidade): ?><span class="tag-oportunidade">Nova oportunidade</span><?php endif; ?>
            <div class="card-media">
                <?php if (count($fotos) > 1): ?>
                <div class="carousel" data-carousel>
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
</main>
</body>
</html>
