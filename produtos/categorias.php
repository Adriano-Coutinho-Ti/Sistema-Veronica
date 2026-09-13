<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    if ($nome === '') {
        $erro = 'Informe o nome da categoria.';
    } else {
        $existe = $pdo->prepare('SELECT id_categoria FROM categorias WHERE nome = :nome');
        $existe->execute([':nome' => $nome]);
        if ($existe->fetch()) {
            $erro = 'Já existe uma categoria com esse nome.';
        } else {
            $pdo->prepare('INSERT INTO categorias (nome) VALUES (:nome)')->execute([':nome' => $nome]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'deletar') {
    $id = (int) $_POST['id_categoria'];
    $emUso = $pdo->prepare('SELECT COUNT(*) FROM produtos WHERE id_categoria = :id');
    $emUso->execute([':id' => $id]);
    if ($emUso->fetchColumn() > 0) {
        $erro = 'Essa categoria está em uso por produtos e não pode ser excluída.';
    } else {
        $pdo->prepare('DELETE FROM categorias WHERE id_categoria = :id')->execute([':id' => $id]);
    }
}

$categorias = $pdo->query(
    'SELECT c.id_categoria, c.nome, COUNT(p.id_produto) AS total_produtos
     FROM categorias c
     LEFT JOIN produtos p ON p.id_categoria = c.id_categoria
     GROUP BY c.id_categoria, c.nome
     ORDER BY c.nome'
)->fetchAll();
$totalCategorias = count($categorias);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Categorias</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 8l10 5 10-5-10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Categorias</h1>
            <span class="subtitulo"><?= $totalCategorias ?> categoria<?= $totalCategorias === 1 ? '' : 's' ?> cadastrada<?= $totalCategorias === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/produtos/lista.php" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar
        </a>
    </p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card">
        <h2>Nova categoria</h2>
        <form method="post" class="linha-form-rapido">
            <input type="hidden" name="acao" value="criar">
            <input type="text" name="nome" placeholder="Nome da categoria" required>
            <button type="submit" class="btn">Adicionar</button>
        </form>
    </div>

    <div class="card">
        <h2>Todas as categorias</h2>
        <?php if (empty($categorias)): ?>
        <p class="alert alert-info">Nenhuma categoria cadastrada ainda.</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Nome</th><th>Produtos</th><th></th></tr>
            <?php foreach ($categorias as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['nome']) ?></td>
                <td><span class="status-pill"><?= (int) $c['total_produtos'] ?></span></td>
                <td class="celula-acoes">
                    <a href="/produtos/variacoes.php?id_categoria=<?= $c['id_categoria'] ?>" class="btn-sm btn-outline">variações</a>
                    <form method="post" data-confirm="Excluir esta categoria?">
                        <input type="hidden" name="acao" value="deletar">
                        <input type="hidden" name="id_categoria" value="<?= $c['id_categoria'] ?>">
                        <button type="submit" class="btn-sm btn-perigo btn-icone" title="Excluir categoria" aria-label="Excluir categoria">
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