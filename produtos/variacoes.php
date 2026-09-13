<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id_categoria = (int) ($_GET['id_categoria'] ?? 0);
$stmtCat = $pdo->prepare('SELECT * FROM categorias WHERE id_categoria = :id');
$stmtCat->execute([':id' => $id_categoria]);
$categoria = $stmtCat->fetch();
if (!$categoria) {
    http_response_code(404);
    echo 'Categoria não encontrada.';
    exit;
}

$erro = '';

// Cria uma variação nova (ex: "Cor") já com seus valores (ex: "Azul, Vermelho")
// e a ativa direto para esta categoria.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar_variacao') {
    $nome = trim($_POST['nome'] ?? '');
    $valoresTexto = trim($_POST['valores'] ?? '');
    $valores = array_filter(array_map('trim', explode(',', $valoresTexto)));

    if ($nome === '' || empty($valores)) {
        $erro = 'Informe o nome da variação e ao menos um valor (separados por vírgula).';
    } else {
        $stmtV = $pdo->prepare('SELECT id_variacao FROM variacoes WHERE nome = :nome');
        $stmtV->execute([':nome' => $nome]);
        $variacao = $stmtV->fetch();

        if (!$variacao) {
            $pdo->prepare('INSERT INTO variacoes (nome) VALUES (:nome)')->execute([':nome' => $nome]);
            $id_variacao = (int) $pdo->lastInsertId();
        } else {
            $id_variacao = (int) $variacao['id_variacao'];
        }

        foreach ($valores as $valor) {
            $existeValor = $pdo->prepare('SELECT id_valor FROM variacao_valores WHERE id_variacao = :iv AND valor = :valor');
            $existeValor->execute([':iv' => $id_variacao, ':valor' => $valor]);
            if (!$existeValor->fetch()) {
                $pdo->prepare('INSERT INTO variacao_valores (id_variacao, valor) VALUES (:iv, :valor)')
                    ->execute([':iv' => $id_variacao, ':valor' => $valor]);
            }
        }

        $existeAssoc = $pdo->prepare('SELECT 1 FROM categoria_variacoes WHERE id_categoria = :ic AND id_variacao = :iv');
        $existeAssoc->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
        if (!$existeAssoc->fetch()) {
            $pdo->prepare('INSERT INTO categoria_variacoes (id_categoria, id_variacao) VALUES (:ic, :iv)')
                ->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
        }
    }
}

// Remove a associação da variação com esta categoria (não deleta a variação em si,
// que pode estar em uso por outras categorias).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover_associacao') {
    $id_variacao = (int) $_POST['id_variacao'];
    $emUso = $pdo->prepare(
        'SELECT COUNT(*) FROM produto_variacao_valores pvv
         JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
         JOIN produto_variacoes pv ON pv.id_produto_variacao = pvv.id_produto_variacao
         JOIN produtos p ON p.id_produto = pv.id_produto
         WHERE vv.id_variacao = :iv AND p.id_categoria = :ic'
    );
    $emUso->execute([':iv' => $id_variacao, ':ic' => $id_categoria]);
    if ($emUso->fetchColumn() > 0) {
        $erro = 'Essa variação está em uso por produtos desta categoria e não pode ser removida.';
    } else {
        $pdo->prepare('DELETE FROM categoria_variacoes WHERE id_categoria = :ic AND id_variacao = :iv')
            ->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
    }
}

$variacoesAtivas = $pdo->prepare(
    'SELECT v.id_variacao, v.nome, GROUP_CONCAT(vv.valor SEPARATOR ", ") AS valores
     FROM categoria_variacoes cv
     JOIN variacoes v ON v.id_variacao = cv.id_variacao
     LEFT JOIN variacao_valores vv ON vv.id_variacao = v.id_variacao
     WHERE cv.id_categoria = :ic
     GROUP BY v.id_variacao, v.nome
     ORDER BY v.nome'
);
$variacoesAtivas->execute([':ic' => $id_categoria]);
$variacoes = $variacoesAtivas->fetchAll();
$totalVariacoes = count($variacoes);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Variações — <?= htmlspecialchars($categoria['nome']) ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 8l10 5 10-5-10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1><?= htmlspecialchars($categoria['nome']) ?></h1>
            <span class="subtitulo"><?= $totalVariacoes ?> variação<?= $totalVariacoes === 1 ? '' : 'ões' ?> configurada<?= $totalVariacoes === 1 ? '' : 's' ?> nesta categoria</span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/produtos/categorias.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar
        </a>
    </p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card">
        <h2>Nova variação</h2>
        <form method="post">
            <input type="hidden" name="acao" value="criar_variacao">
            <label>Nome da variação (ex: Cor)<input type="text" name="nome" required></label>
            <label>Valores, separados por vírgula (ex: Azul, Vermelho)<input type="text" name="valores" required></label>
            <button type="submit" class="btn">Adicionar / atualizar</button>
        </form>
    </div>

    <div class="card">
        <h2>Variações desta categoria</h2>
        <?php if (empty($variacoes)): ?>
        <p class="alert alert-info">Nenhuma variação cadastrada ainda pra "<?= htmlspecialchars($categoria['nome']) ?>".</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Variação</th><th>Valores</th><th></th></tr>
            <?php foreach ($variacoes as $v): ?>
            <tr>
                <td><?= htmlspecialchars($v['nome']) ?></td>
                <td><?= htmlspecialchars($v['valores'] ?? '') ?></td>
                <td class="celula-acoes">
                    <a href="/produtos/variacao_editar.php?id_variacao=<?= $v['id_variacao'] ?>&id_categoria=<?= $id_categoria ?>" class="btn-sm btn-outline">editar</a>
                    <form method="post" data-confirm="Remover esta variação desta categoria?">
                        <input type="hidden" name="acao" value="remover_associacao">
                        <input type="hidden" name="id_variacao" value="<?= $v['id_variacao'] ?>">
                        <button type="submit" class="btn-sm btn-perigo btn-icone" title="Remover variação" aria-label="Remover variação">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3m-8 0 1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M10 11v6M14 11v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
