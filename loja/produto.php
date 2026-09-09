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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title><?= htmlspecialchars($produto['nome']) ?></title></head>
<body>
    <p><a href="/loja/index.php">Voltar</a></p>
    <h1><?= htmlspecialchars($produto['nome']) ?></h1>
    <p><?= htmlspecialchars($produto['descricao'] ?? '') ?></p>
    <p>Categoria: <?= htmlspecialchars($produto['categoria']) ?></p>

    <div>
        <?php foreach ($listaFotos as $foto): ?>
            <img src="/<?= htmlspecialchars($foto) ?>" width="150">
        <?php endforeach; ?>
    </div>

    <?php if (empty($_SESSION['id_cliente'])): ?>
        <p><a href="/loja/cadastro.php">Entre ou cadastre-se</a> pra comprar.</p>
    <?php elseif (empty($listaCombinacoes)): ?>
        <p>Sem estoque disponível no momento.</p>
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
        <button type="submit">Adicionar ao carrinho</button>
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
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
