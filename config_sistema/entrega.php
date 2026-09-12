<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirAdmin();

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'criar') {
    $nome = trim($_POST['nome'] ?? '');
    $prazo_dias = $_POST['prazo_dias'] !== '' ? (int) $_POST['prazo_dias'] : null;
    $custo = (float) str_replace(',', '.', $_POST['custo'] ?? '0');

    if ($nome === '') {
        $erro = 'Informe o nome da forma de entrega.';
    } else {
        $pdo->prepare('INSERT INTO formas_entrega (nome, tipo, prazo_dias, custo, ativo, fixa) VALUES (:nome, "entrega", :prazo, :custo, 1, 0)')
            ->execute([':nome' => $nome, ':prazo' => $prazo_dias, ':custo' => $custo]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_retirada') {
    $prazo_dias = (int) ($_POST['prazo_retirada'] ?? 0);
    $pdo->prepare('UPDATE formas_entrega SET prazo_dias = :prazo WHERE fixa = 1')->execute([':prazo' => $prazo_dias]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'alternar_ativo') {
    $id = (int) $_POST['id_entrega'];
    $pdo->prepare('UPDATE formas_entrega SET ativo = NOT ativo WHERE id_entrega = :id AND fixa = 0')->execute([':id' => $id]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'deletar') {
    $id = (int) $_POST['id_entrega'];
    $pdo->prepare('DELETE FROM formas_entrega WHERE id_entrega = :id AND fixa = 0')->execute([':id' => $id]);
}

$formas = $pdo->query('SELECT * FROM formas_entrega ORDER BY fixa DESC, nome')->fetchAll();
$retirada = array_values(array_filter($formas, fn($f) => (int) $f['fixa'] === 1))[0] ?? null;
$entregas = array_values(array_filter($formas, fn($f) => (int) $f['fixa'] === 0));
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Formas de entrega</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Formas de entrega</h1>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <?php if ($retirada): ?>
    <div class="card">
    <h3>Retirar na loja (fixa)</h3>
    <form method="post" class="form-linha-compacta">
        <input type="hidden" name="acao" value="atualizar_retirada">
        <label>Prazo de tolerância para retirada (dias)
            <input type="number" min="0" name="prazo_retirada" value="<?= (int) $retirada['prazo_dias'] ?>">
        </label>
        <button type="submit">Salvar prazo</button>
    </form>
    </div>
    <?php endif; ?>

    <h3>Outras formas de entrega</h3>
    <form method="post" class="linha-form-rapido" style="max-width:600px;">
        <input type="hidden" name="acao" value="criar">
        <input type="text" name="nome" placeholder="Nome (ex: Motoboy)" required>
        <input type="number" name="prazo_dias" placeholder="Prazo em dias">
        <input type="text" name="custo" placeholder="Custo (R$)">
        <button type="submit">Adicionar</button>
    </form>
    <div class="tabela-wrap">
    <table>
        <tr><th>Nome</th><th>Prazo (dias)</th><th>Custo</th><th>Ativo</th><th></th></tr>
        <?php foreach ($entregas as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['nome']) ?></td>
            <td><?= $e['prazo_dias'] !== null ? (int) $e['prazo_dias'] : '—' ?></td>
            <td>R$ <?= number_format($e['custo'], 2, ',', '.') ?></td>
            <td><span class="status-pill<?= $e['ativo'] ? ' sucesso' : '' ?>"><?= $e['ativo'] ? 'Sim' : 'Não' ?></span></td>
            <td class="celula-acoes">
                <form method="post">
                    <input type="hidden" name="acao" value="alternar_ativo">
                    <input type="hidden" name="id_entrega" value="<?= $e['id_entrega'] ?>">
                    <button type="submit" class="btn-sm btn-outline"><?= $e['ativo'] ? 'desativar' : 'ativar' ?></button>
                </form>
                <form method="post" onsubmit="return confirm('Excluir esta forma de entrega?');">
                    <input type="hidden" name="acao" value="deletar">
                    <input type="hidden" name="id_entrega" value="<?= $e['id_entrega'] ?>">
                    <button type="submit" class="btn-sm btn-perigo">excluir</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
    </div>
</main>
</body>
</html>
