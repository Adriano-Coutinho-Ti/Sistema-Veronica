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

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><title>Categorias</title></head>
<body>
    <h1>Categorias</h1>
    <?php if ($erro): ?><p style="color:red;"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <form method="post">
        <input type="hidden" name="acao" value="criar">
        <input type="text" name="nome" placeholder="Nome da categoria" required>
        <button type="submit">Adicionar</button>
    </form>
    <table border="1" cellpadding="6">
        <?php foreach ($categorias as $c): ?>
        <tr>
            <td><?= htmlspecialchars($c['nome']) ?></td>
            <td>
                <a href="/produtos/variacoes.php?id_categoria=<?= $c['id_categoria'] ?>">variações</a>
                <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta categoria?');">
                    <input type="hidden" name="acao" value="deletar">
                    <input type="hidden" name="id_categoria" value="<?= $c['id_categoria'] ?>">
                    <button type="submit">excluir</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>