<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
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
    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Produto criado com sucesso.</p><?php endif; ?>
    <p><a href="/produtos/novo.php" class="btn">+ Novo produto</a> <a href="/produtos/categorias.php" class="btn-outline">Categorias</a></p>
    <div class="tabela-wrap">
    <table>
        <tr><th>Nome</th><th>Categoria</th><th>Preço base</th><th>Estoque total</th><th>Ativo</th><th></th><th></th></tr>
        <?php foreach ($produtos as $p): ?>
        <?php
            $urlProdutoP = 'https://brechodaveve.codernex.com.br/loja/produto.php?id=' . $p['id_produto'];
            $linkWhatsappP = montarLinkCompartilharWhatsapp($p['nome'], (float) $p['preco_base'], $urlProdutoP);
        ?>
        <tr>
            <td><?= htmlspecialchars($p['nome']) ?></td>
            <td><?= htmlspecialchars($p['categoria']) ?></td>
            <td>R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></td>
            <td><?= (int) $p['estoque_total'] ?></td>
            <td><?= $p['ativo'] ? 'Sim' : 'Não' ?></td>
            <td><a href="/produtos/editar.php?id=<?= $p['id_produto'] ?>">editar</a></td>
            <td>
                <a href="<?= htmlspecialchars($linkWhatsappP) ?>" target="_blank" rel="noopener" class="btn-compartilhar-whatsapp icone-so" aria-label="Compartilhar no WhatsApp" title="Compartilhar no WhatsApp">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
                </a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
</main>
</body>
</html>
