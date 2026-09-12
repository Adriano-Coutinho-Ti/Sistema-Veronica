<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
exigirLogin();

$id_produto = (int) ($_GET['id'] ?? 0);
$stmtP = $pdo->prepare('SELECT * FROM produtos WHERE id_produto = :id');
$stmtP->execute([':id' => $id_produto]);
$produto = $stmtP->fetch();

if (!$produto) {
    http_response_code(404);
    echo 'Produto não encontrado.';
    exit;
}

$fotos = $pdo->prepare('SELECT id_foto, ordem, caminho_arquivo FROM produto_fotos WHERE id_produto = :ip ORDER BY ordem');
$fotos->execute([':ip' => $id_produto]);
$listaFotos = $fotos->fetchAll();

$urlProdutoAbsoluta = 'https://brechodaveve.codernex.com.br/loja/produto.php?id=' . $id_produto;
$linkCompartilharWhatsapp = montarLinkCompartilharWhatsapp($produto['nome'], (float) $produto['preco_base'], $urlProdutoAbsoluta);

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar') {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '') ?: null;
    $preco_base = (float) str_replace(',', '.', $_POST['preco_base'] ?? '0');
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($nome === '' || $preco_base <= 0) {
        $erro = 'Nome e preço base são obrigatórios.';
    } else {
        $pdo->prepare('UPDATE produtos SET nome = :nome, descricao = :descricao, preco_base = :preco_base, ativo = :ativo WHERE id_produto = :id')
            ->execute([':nome' => $nome, ':descricao' => $descricao, ':preco_base' => $preco_base, ':ativo' => $ativo, ':id' => $id_produto]);

        foreach ($_POST['estoque'] ?? [] as $id_pv => $valor) {
            $pdo->prepare('UPDATE produto_variacoes SET estoque = :estoque WHERE id_produto_variacao = :id AND id_produto = :ip')
                ->execute([':estoque' => (int) $valor, ':id' => (int) $id_pv, ':ip' => $id_produto]);
        }
        foreach ($_POST['preco'] ?? [] as $id_pv => $valor) {
            $precoCombinacao = $valor === '' ? null : (float) str_replace(',', '.', $valor);
            $pdo->prepare('UPDATE produto_variacoes SET preco = :preco WHERE id_produto_variacao = :id AND id_produto = :ip')
                ->execute([':preco' => $precoCombinacao, ':id' => (int) $id_pv, ':ip' => $id_produto]);
        }

        header('Location: /produtos/editar.php?id=' . $id_produto . '&atualizado=1');
        exit;
    }
}

$combinacoes = $pdo->prepare(
    'SELECT pv.id_produto_variacao, pv.preco, pv.estoque,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao
     FROM produto_variacoes pv
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE pv.id_produto = :ip
     GROUP BY pv.id_produto_variacao, pv.preco, pv.estoque'
);
$combinacoes->execute([':ip' => $id_produto]);
$listaCombinacoes = $combinacoes->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Editar produto</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Editar produto</h1>
    <?php if (isset($_GET['atualizado'])): ?><p style="color:green;">Produto atualizado.</p><?php endif; ?>
    <?php if (isset($_GET['criado'])): ?><p style="color:green;">Produto criado com sucesso.</p><?php endif; ?>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <a href="<?= htmlspecialchars($linkCompartilharWhatsapp) ?>" target="_blank" rel="noopener" class="btn-compartilhar-whatsapp">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
        Compartilhar no WhatsApp
    </a>
    <?php if (empty($listaFotos)): ?>
        <p style="color:#6B7280; font-size:0.9rem;">Adicione uma foto abaixo pra ela aparecer na prévia do link quando compartilhar.</p>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="acao" value="atualizar">
        <label>Nome<br><input type="text" name="nome" value="<?= htmlspecialchars($produto['nome']) ?>" required></label><br>
        <label>Descrição<br><textarea name="descricao"><?= htmlspecialchars($produto['descricao'] ?? '') ?></textarea></label><br>
        <label>Preço base (R$)<br><input type="text" name="preco_base" value="<?= number_format($produto['preco_base'], 2, ',', '') ?>" required></label><br>
        <label><input type="checkbox" name="ativo" <?= $produto['ativo'] ? 'checked' : '' ?>> Ativo (visível na loja)</label><br>

        <h3>Combinações</h3>
        <table border="1" cellpadding="6">
            <tr><th>Combinação</th><th>Estoque</th><th>Preço (branco = usa o base)</th></tr>
            <?php foreach ($listaCombinacoes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['descricao'] ?? 'Padrão (sem variação)') ?></td>
                <td><input type="number" min="0" name="estoque[<?= $c['id_produto_variacao'] ?>]" value="<?= (int) $c['estoque'] ?>"></td>
                <td><input type="text" name="preco[<?= $c['id_produto_variacao'] ?>]" value="<?= $c['preco'] !== null ? number_format($c['preco'], 2, ',', '') : '' ?>"></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <button type="submit">Salvar alterações</button>
    </form>

    <h3>Fotos (<?= count($listaFotos) ?>/5)</h3>
    <?php if (isset($_GET['upload_status']) && $_GET['upload_status'] === 'error'): ?>
        <p style="color:red;">Falha ao enviar a foto (<?= htmlspecialchars($_GET['msg'] ?? 'erro desconhecido') ?>).</p>
    <?php endif; ?>
    <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php foreach ($listaFotos as $f): ?>
        <div>
            <img src="/<?= htmlspecialchars($f['caminho_arquivo']) ?>" width="120" alt="Foto do produto">
            <form method="post" action="/produtos/ajax/deletar_foto.php" onsubmit="return confirm('Remover esta foto?');">
                <input type="hidden" name="id_foto" value="<?= $f['id_foto'] ?>">
                <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
                <button type="submit">remover</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (count($listaFotos) < 5): ?>
    <form method="post" action="/produtos/ajax/upload_foto.php" enctype="multipart/form-data">
        <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
        <input type="file" name="foto" accept="image/png,image/jpeg,image/gif" required>
        <button type="submit">Enviar foto</button>
    </form>
    <?php endif; ?>

    <form method="post" action="/produtos/ajax/deletar_produto.php" onsubmit="return confirm('Excluir este produto e suas fotos definitivamente?');">
        <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
        <button type="submit">Excluir produto</button>
    </form>

    <p><a href="/produtos/lista.php">Voltar</a></p>
</main>
</body>
</html>
