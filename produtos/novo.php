<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
exigirLogin();

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '') ?: null;
    $id_categoria = (int) ($_POST['id_categoria'] ?? 0);
    $condicao = $_POST['condicao'] === 'novo' ? 'novo' : 'usado';
    $preco_base = (float) str_replace(',', '.', $_POST['preco_base'] ?? '0');
    // combinacoes[]: cada item é um JSON {"valores":[id_valor,...],"preco":x,"estoque":y}
    $combinacoesJson = $_POST['combinacoes'] ?? '[]';
    $combinacoes = json_decode($combinacoesJson, true) ?: [];

    if ($nome === '' || $id_categoria <= 0 || $preco_base <= 0) {
        $erro = 'Preencha nome, categoria e um preço base válido.';
    } elseif (empty($combinacoes)) {
        $erro = 'É preciso informar estoque de ao menos uma combinação (ou deixar sem variação para usar o padrão).';
    } else {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO produtos (nome, descricao, id_categoria, condicao, preco_base) VALUES (:nome, :descricao, :ic, :condicao, :preco_base)'
            );
            $stmt->execute([
                ':nome' => $nome,
                ':descricao' => $descricao,
                ':ic' => $id_categoria,
                ':condicao' => $condicao,
                ':preco_base' => $preco_base,
            ]);
            $id_produto = (int) $pdo->lastInsertId();

            foreach ($combinacoes as $combinacao) {
                $precoCombinacao = isset($combinacao['preco']) && $combinacao['preco'] !== ''
                    ? (float) str_replace(',', '.', $combinacao['preco'])
                    : null;
                $estoque = (int) ($combinacao['estoque'] ?? 0);

                $stmtPv = $pdo->prepare(
                    'INSERT INTO produto_variacoes (id_produto, preco, estoque) VALUES (:ip, :preco, :estoque)'
                );
                $stmtPv->execute([':ip' => $id_produto, ':preco' => $precoCombinacao, ':estoque' => $estoque]);
                $id_produto_variacao = (int) $pdo->lastInsertId();

                foreach (($combinacao['valores'] ?? []) as $id_valor) {
                    $pdo->prepare(
                        'INSERT INTO produto_variacao_valores (id_produto_variacao, id_valor) VALUES (:ipv, :iv)'
                    )->execute([':ipv' => $id_produto_variacao, ':iv' => (int) $id_valor]);
                }
            }

            $pdo->commit();
            header('Location: /produtos/editar.php?id=' . $id_produto . '&criado=1');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Novo produto</title></head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <h1>Novo produto</h1>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <div class="card">
    <form method="post" id="form-produto">
        <label>Nome<input type="text" name="nome" required></label>
        <label>Descrição<textarea name="descricao"></textarea></label>
        <label>Categoria
            <select name="id_categoria" id="id_categoria" required>
                <option value="">Selecione</option>
                <?php foreach ($categorias as $c): ?>
                <option value="<?= $c['id_categoria'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Condição
            <select name="condicao">
                <option value="usado">Usado</option>
                <option value="novo">Novo</option>
            </select>
        </label>
        <label>Preço base (R$)<input type="text" name="preco_base" required></label>

        <div id="variacoes-disponiveis" class="lista-checkbox"></div>

        <h3>Combinações e estoque</h3>
        <div id="combinacoes-container"></div>
        <input type="hidden" name="combinacoes" id="combinacoes-input">

        <button type="submit">Salvar produto</button>
    </form>
    </div>

<script>
document.getElementById('id_categoria').addEventListener('change', carregarVariacoes);

function carregarVariacoes() {
    const idCategoria = document.getElementById('id_categoria').value;
    const container = document.getElementById('variacoes-disponiveis');
    container.innerHTML = '';
    document.getElementById('combinacoes-container').innerHTML = '';
    if (!idCategoria) return;

    fetch('/produtos/ajax/listar_variacoes_categoria.php?id_categoria=' + idCategoria)
        .then(r => r.json())
        .then(data => {
            data.variacoes.forEach(v => {
                const label = document.createElement('label');
                label.style.display = 'block';
                const checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'chk-variacao';
                checkbox.value = v.id_variacao;
                label.appendChild(checkbox);
                label.appendChild(document.createTextNode(' ' + v.nome));
                container.appendChild(label);
            });
            container.querySelectorAll('.chk-variacao').forEach(chk => {
                chk.addEventListener('change', atualizarCombinacoes);
            });
            // Renderiza a combinação "Padrão" imediatamente, mesmo sem nenhuma
            // variação marcada — sem isso não haveria campo de estoque/preço
            // nenhum pra um produto sem variação, e ele seria salvo com estoque 0.
            atualizarCombinacoes();
        });
}

function atualizarCombinacoes() {
    const idCategoria = document.getElementById('id_categoria').value;
    const marcadas = Array.from(document.querySelectorAll('.chk-variacao:checked')).map(c => c.value);
    const container = document.getElementById('combinacoes-container');
    container.innerHTML = 'Carregando...';

    fetch('/produtos/ajax/gerar_combinacoes.php?id_categoria=' + idCategoria + '&variacoes=' + marcadas.join(','))
        .then(r => r.json())
        .then(data => {
            container.innerHTML = '';
            data.combinacoes.forEach((combinacao, index) => {
                const nomeCombinacao = combinacao.map(v => v.valor).join(' / ') || 'Padrão (sem variação)';
                const div = document.createElement('div');
                const strong = document.createElement('strong');
                strong.textContent = nomeCombinacao;
                div.appendChild(strong);
                div.insertAdjacentHTML('beforeend', ' — Estoque: <input type="number" min="0" class="input-estoque" value="0"> Preço (deixe em branco para usar o preço base): <input type="text" class="input-preco">');
                div.dataset.valores = JSON.stringify(combinacao.map(v => v.id_valor));
                container.appendChild(div);
            });
        });
}

document.getElementById('form-produto').addEventListener('submit', function (e) {
    const linhas = document.querySelectorAll('#combinacoes-container > div');
    const combinacoes = Array.from(linhas).map(div => ({
        valores: JSON.parse(div.dataset.valores),
        estoque: div.querySelector('.input-estoque').value,
        preco: div.querySelector('.input-preco').value,
    }));
    document.getElementById('combinacoes-input').value = JSON.stringify(combinacoes);
});
</script>
</main>
</body>
</html>
