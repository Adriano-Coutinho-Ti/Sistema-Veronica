<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$id_variacao = (int) ($_GET['id_variacao'] ?? 0);
$id_categoria = (int) ($_GET['id_categoria'] ?? 0);

$stmtVariacao = $pdo->prepare('SELECT id_variacao, nome FROM variacoes WHERE id_variacao = :id');
$stmtVariacao->execute([':id' => $id_variacao]);
$variacao = $stmtVariacao->fetch();

if (!$variacao) {
    http_response_code(404);
    echo 'Variação não encontrada.';
    exit;
}

$linkVoltar = $id_categoria > 0 ? '/produtos/variacoes.php?id_categoria=' . $id_categoria : '/produtos/categorias.php';
$erro = '';

// valor usado em produto_variacao_valores não pode ser apagado (a FK pra
// variacao_valores não tem ON DELETE CASCADE de propósito — um valor em uso
// é histórico de venda/estoque real, não dá pra sumir com ele por baixo).
function valorEmUso(PDO $pdo, int $id_valor): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM produto_variacao_valores WHERE id_valor = :iv');
    $stmt->execute([':iv' => $id_valor]);
    return $stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'renomear_valor') {
    $id_valor = (int) ($_POST['id_valor'] ?? 0);
    $novoValor = trim($_POST['novo_valor'] ?? '');

    if ($novoValor === '') {
        $erro = 'Informe um nome pro valor.';
    } else {
        $existe = $pdo->prepare('SELECT id_valor FROM variacao_valores WHERE id_variacao = :iv AND valor = :valor AND id_valor <> :id');
        $existe->execute([':iv' => $id_variacao, ':valor' => $novoValor, ':id' => $id_valor]);
        if ($existe->fetch()) {
            $erro = 'Já existe um valor com esse nome nessa variação.';
        } else {
            $pdo->prepare('UPDATE variacao_valores SET valor = :valor WHERE id_valor = :id AND id_variacao = :iv')
                ->execute([':valor' => $novoValor, ':id' => $id_valor, ':iv' => $id_variacao]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'adicionar_valores') {
    $valoresTexto = trim($_POST['valores'] ?? '');
    $novosValores = array_filter(array_map('trim', explode(',', $valoresTexto)));

    if (empty($novosValores)) {
        $erro = 'Informe ao menos um valor (separados por vírgula).';
    } else {
        foreach ($novosValores as $valor) {
            $existe = $pdo->prepare('SELECT id_valor FROM variacao_valores WHERE id_variacao = :iv AND valor = :valor');
            $existe->execute([':iv' => $id_variacao, ':valor' => $valor]);
            if (!$existe->fetch()) {
                $pdo->prepare('INSERT INTO variacao_valores (id_variacao, valor) VALUES (:iv, :valor)')
                    ->execute([':iv' => $id_variacao, ':valor' => $valor]);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'remover_valor') {
    $id_valor = (int) ($_POST['id_valor'] ?? 0);
    if (valorEmUso($pdo, $id_valor)) {
        $erro = 'Esse valor está em uso por produtos e não pode ser removido.';
    } else {
        $pdo->prepare('DELETE FROM variacao_valores WHERE id_valor = :id AND id_variacao = :iv')
            ->execute([':id' => $id_valor, ':iv' => $id_variacao]);
    }
}

$stmtValores = $pdo->prepare('SELECT id_valor, valor FROM variacao_valores WHERE id_variacao = :iv ORDER BY valor');
$stmtValores->execute([':iv' => $id_variacao]);
$valores = $stmtValores->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Editar variação — <?= htmlspecialchars($variacao['nome']) ?></title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 2 8l10 5 10-5-10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1><?= htmlspecialchars($variacao['nome']) ?></h1>
            <span class="subtitulo"><?= count($valores) ?> valor<?= count($valores) === 1 ? '' : 'es' ?> cadastrado<?= count($valores) === 1 ? '' : 's' ?></span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="<?= htmlspecialchars($linkVoltar) ?>" class="btn-outline btn-sm">
            <svg viewBox="0 0 24 24" aria-hidden="true" width="14" height="14"><path d="M15 6 9 12l6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Voltar
        </a>
    </p>

    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>

    <div class="card">
        <h2>Adicionar valores</h2>
        <form method="post" class="linha-form-rapido">
            <input type="hidden" name="acao" value="adicionar_valores">
            <input type="text" name="valores" placeholder="Ex: Rosa, Bege" required>
            <button type="submit" class="btn">Adicionar</button>
        </form>
    </div>

    <div class="card">
        <h2>Valores desta variação</h2>
        <?php if (empty($valores)): ?>
        <p class="alert alert-info">Nenhum valor cadastrado ainda.</p>
        <?php else: ?>
        <div class="tabela-wrap">
        <table>
            <tr><th>Valor</th><th></th></tr>
            <?php foreach ($valores as $v): ?>
            <tr>
                <td>
                    <form method="post" class="form-linha-compacta">
                        <input type="hidden" name="acao" value="renomear_valor">
                        <input type="hidden" name="id_valor" value="<?= $v['id_valor'] ?>">
                        <input type="text" name="novo_valor" value="<?= htmlspecialchars($v['valor']) ?>">
                        <button type="submit" class="btn-sm btn-outline">salvar</button>
                    </form>
                </td>
                <td class="celula-acoes">
                    <form method="post" data-confirm="Remover o valor '<?= htmlspecialchars($v['valor'], ENT_QUOTES) ?>'?">
                        <input type="hidden" name="acao" value="remover_valor">
                        <input type="hidden" name="id_valor" value="<?= $v['id_valor'] ?>">
                        <button type="submit" class="btn-sm btn-perigo btn-icone" title="Remover valor" aria-label="Remover valor">
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
