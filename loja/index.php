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
<head><meta charset="UTF-8"><title>Loja</title></head>
<body>
    <h1>Loja</h1>
    <p>
        <?php if (!empty($_SESSION['id_cliente'])): ?>
            Olá, <?= htmlspecialchars($_SESSION['nome_cliente']) ?> —
            <a href="/loja/carrinho.php">Carrinho</a> | <a href="/loja/logout.php">Sair</a>
        <?php else: ?>
            <a href="/loja/cadastro.php">Entrar / Cadastrar</a>
        <?php endif; ?>
    </p>

    <nav>
        <a href="/loja/index.php">Todas as categorias</a>
        <?php foreach ($categorias as $c): ?>
            | <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>"><?= htmlspecialchars($c['nome']) ?></a>
        <?php endforeach; ?>
    </nav>

    <div>
        <?php foreach ($produtos as $p): ?>
        <div>
            <a href="/loja/produto.php?id=<?= $p['id_produto'] ?>">
                <?php if ($p['foto']): ?><img src="/<?= htmlspecialchars($p['foto']) ?>" width="150"><?php endif; ?>
                <div><?= htmlspecialchars($p['nome']) ?></div>
                <div>R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>
