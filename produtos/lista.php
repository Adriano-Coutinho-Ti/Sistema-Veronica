<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$produtos = $pdo->query(
    'SELECT p.id_produto, p.nome, p.preco_base, p.ativo, c.nome AS categoria,
            COALESCE(SUM(pv.estoque), 0) AS estoque_total
     FROM produtos p
     JOIN categorias c ON c.id_categoria = p.id_categoria
     LEFT JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
     GROUP BY p.id_produto, p.nome, p.preco_base, p.ativo, c.nome
     ORDER BY p.criado_em DESC'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Produtos</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Produtos</h1>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Produto criado com sucesso.</p><?php endif; ?>
    <p><a href="/produtos/novo.php">+ Novo produto</a> | <a href="/produtos/categorias.php">Categorias</a></p>
    <table border="1" cellpadding="6">
        <tr><th>Nome</th><th>Categoria</th><th>Preço base</th><th>Estoque total</th><th>Ativo</th><th></th></tr>
        <?php foreach ($produtos as $p): ?>
        <tr>
            <td><?= htmlspecialchars($p['nome']) ?></td>
            <td><?= htmlspecialchars($p['categoria']) ?></td>
            <td>R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></td>
            <td><?= (int) $p['estoque_total'] ?></td>
            <td><?= $p['ativo'] ? 'Sim' : 'Não' ?></td>
            <td><a href="/produtos/editar.php?id=<?= $p['id_produto'] ?>">editar</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
</main>
</body>
</html>
