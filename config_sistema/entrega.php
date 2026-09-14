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
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 16V6a1 1 0 0 1 1-1h9v11M3 16h10M3 16a2 2 0 1 0 4 0M13 16a2 2 0 1 0 4 0M17 16h3v-4l-2.5-3H16v7M13 9h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1>Formas de entrega</h1>
            <span class="subtitulo">Retirada na loja e opções de entrega da loja online</span>
        </div>
    </div>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="grade-2col">
        <?php if ($retirada): ?>
        <div class="card">
        <h3>Retirar na loja (fixa)</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px;">Sempre disponível pro cliente — não pode ser desativada nem excluída.</p>
        <form method="post" class="form-linha-compacta">
            <input type="hidden" name="acao" value="atualizar_retirada">
            <label>Prazo de tolerância para retirada (dias)
                <input type="number" min="0" name="prazo_retirada" value="<?= (int) $retirada['prazo_dias'] ?>">
            </label>
            <button type="submit">Salvar prazo</button>
        </form>
        </div>
        <?php endif; ?>

        <div class="card">
        <h3>Adicionar forma de entrega</h3>
        <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:-8px;">Ex: motoboy, transportadora, correios.</p>
        <form method="post" class="linha-form-rapido">
            <input type="hidden" name="acao" value="criar">
            <input type="text" name="nome" placeholder="Nome (ex: Motoboy)" required>
            <input type="number" name="prazo_dias" placeholder="Prazo em dias">
            <input type="text" name="custo" placeholder="Custo (R$)">
            <button type="submit">Adicionar</button>
        </form>
        </div>
    </div>

    <div class="card" style="margin-top:20px;">
    <h3>Outras formas de entrega cadastradas</h3>
    <?php if (empty($entregas)): ?>
    <p class="alert alert-info">Nenhuma outra forma de entrega cadastrada ainda.</p>
    <?php else: ?>
    <div class="tabela-wrap">
    <table>
        <tr><th>Nome</th><th>Prazo (dias)</th><th>Custo</th><th>Ativo</th><th></th></tr>
        <?php foreach ($entregas as $e): ?>
        <tr>
            <td><?= htmlspecialchars($e['nome']) ?></td>
            <td><?= $e['prazo_dias'] !== null ? (int) $e['prazo_dias'] : '—' ?></td>
            <td>R$ <?= number_format($e['custo'], 2, ',', '.') ?></td>
            <td><span class="status-pill<?= $e['ativo'] ? ' sucesso' : ' erro' ?>"><?= $e['ativo'] ? 'Ativo' : 'Inativo' ?></span></td>
            <td class="celula-acoes">
                <form method="post">
                    <input type="hidden" name="acao" value="alternar_ativo">
                    <input type="hidden" name="id_entrega" value="<?= $e['id_entrega'] ?>">
                    <button type="submit" class="btn-sm btn-outline"><?= $e['ativo'] ? 'desativar' : 'ativar' ?></button>
                </form>
                <form method="post" data-confirm="Excluir esta forma de entrega?">
                    <input type="hidden" name="acao" value="deletar">
                    <input type="hidden" name="id_entrega" value="<?= $e['id_entrega'] ?>">
                    <button type="submit" class="btn-sm btn-perigo">excluir</button>
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
