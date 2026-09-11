<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

liberarReservasExpiradas($pdo);

$id_categoria = (int) ($_GET['categoria'] ?? 0);

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();

$sql = "SELECT DISTINCT p.id_produto, p.nome, p.preco_base,
               (SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = p.id_produto ORDER BY ordem LIMIT 1) AS foto
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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Loja</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <h1>Loja</h1>

    <nav>
        <a href="/loja/index.php">Todas as categorias</a>
        <?php foreach ($categorias as $c): ?>
            | <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>"><?= htmlspecialchars($c['nome']) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="product-grid">
        <?php foreach ($produtos as $p): ?>
        <a href="/loja/produto.php?id=<?= $p['id_produto'] ?>" class="product-card">
            <?php if ($p['foto']): ?><img src="/<?= htmlspecialchars($p['foto']) ?>" alt="<?= htmlspecialchars($p['nome']) ?>"><?php endif; ?>
            <div><?= htmlspecialchars($p['nome']) ?></div>
            <div class="price">R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></div>
        </a>
        <?php endforeach; ?>
    </div>
</main>
</body>
</html>
