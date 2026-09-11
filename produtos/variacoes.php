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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Variações — <?= htmlspecialchars($categoria['nome']) ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Variações de "<?= htmlspecialchars($categoria['nome']) ?>"</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="acao" value="criar_variacao">
        <label>Nome da variação (ex: Cor)<br><input type="text" name="nome" required></label><br>
        <label>Valores, separados por vírgula (ex: Azul, Vermelho)<br><input type="text" name="valores" required style="width:400px;"></label><br>
        <button type="submit">Adicionar / atualizar</button>
    </form>
    <table border="1" cellpadding="6">
        <tr><th>Variação</th><th>Valores</th><th></th></tr>
        <?php foreach ($variacoes as $v): ?>
        <tr>
            <td><?= htmlspecialchars($v['nome']) ?></td>
            <td><?= htmlspecialchars($v['valores'] ?? '') ?></td>
            <td>
                <form method="post" onsubmit="return confirm('Remover esta variação desta categoria?');">
                    <input type="hidden" name="acao" value="remover_associacao">
                    <input type="hidden" name="id_variacao" value="<?= $v['id_variacao'] ?>">
                    <button type="submit">remover</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p><a href="/produtos/categorias.php">Voltar para categorias</a></p>
</main>
</body>
</html>
