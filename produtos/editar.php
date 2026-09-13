<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/loja.php';
exigirLogin();

$id_produto = (int) ($_GET['id'] ?? 0);
$stmtP = $pdo->prepare(
    'SELECT p.*, c.nome AS categoria FROM produtos p JOIN categorias c ON c.id_categoria = p.id_categoria WHERE p.id_produto = :id'
);
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

// Cria combinações novas (produto_variacoes) a partir das variações marcadas,
// sem nunca apagar as que já existem — só soma. Assim, quando o lojista
// cadastra um valor novo numa variação (ex: adiciona "Rosa" em Cor lá em
// produtos/variacoes.php) e volta pra cá, basta clicar em "Atualizar
// combinações" de novo pra ela aparecer pronta pra receber estoque.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'sincronizar_variacoes') {
    $idsVariacaoSelecionadas = array_unique(array_filter(array_map('intval', $_POST['variacoes'] ?? [])));

    $gruposValores = [];
    foreach ($idsVariacaoSelecionadas as $idVariacao) {
        $stmtValores = $pdo->prepare(
            'SELECT vv.id_valor FROM variacao_valores vv
             JOIN categoria_variacoes cv ON cv.id_variacao = vv.id_variacao
             WHERE vv.id_variacao = :iv AND cv.id_categoria = :ic
             ORDER BY vv.valor'
        );
        $stmtValores->execute([':iv' => $idVariacao, ':ic' => $produto['id_categoria']]);
        $valoresDaVariacao = $stmtValores->fetchAll(PDO::FETCH_COLUMN);
        if ($valoresDaVariacao) {
            $gruposValores[] = $valoresDaVariacao;
        }
    }

    $combinacoesGeradas = [[]];
    foreach ($gruposValores as $grupo) {
        $novasCombinacoes = [];
        foreach ($combinacoesGeradas as $combinacaoAtual) {
            foreach ($grupo as $idValor) {
                $novasCombinacoes[] = array_merge($combinacaoAtual, [$idValor]);
            }
        }
        $combinacoesGeradas = $novasCombinacoes;
    }

    $stmtExistentes = $pdo->prepare(
        'SELECT pv.id_produto_variacao, GROUP_CONCAT(pvv.id_valor ORDER BY pvv.id_valor SEPARATOR ",") AS chave
         FROM produto_variacoes pv
         LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
         WHERE pv.id_produto = :ip
         GROUP BY pv.id_produto_variacao'
    );
    $stmtExistentes->execute([':ip' => $id_produto]);
    $chavesExistentes = array_flip(array_column($stmtExistentes->fetchAll(), 'chave'));

    foreach ($combinacoesGeradas as $combinacao) {
        if (empty($combinacao)) {
            continue;
        }
        sort($combinacao);
        $chave = implode(',', $combinacao);
        if (isset($chavesExistentes[$chave])) {
            continue;
        }

        $pdo->prepare('INSERT INTO produto_variacoes (id_produto, preco, estoque) VALUES (:ip, NULL, 0)')
            ->execute([':ip' => $id_produto]);
        $idNovaCombinacao = (int) $pdo->lastInsertId();

        foreach ($combinacao as $idValor) {
            $pdo->prepare('INSERT INTO produto_variacao_valores (id_produto_variacao, id_valor) VALUES (:ipv, :iv)')
                ->execute([':ipv' => $idNovaCombinacao, ':iv' => $idValor]);
        }
    }

    header('Location: /produtos/editar.php?id=' . $id_produto . '&variacoes_atualizadas=1');
    exit;
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

$stmtVariacoesCategoria = $pdo->prepare(
    'SELECT v.id_variacao, v.nome, GROUP_CONCAT(vv.valor ORDER BY vv.valor SEPARATOR ", ") AS valores
     FROM categoria_variacoes cv
     JOIN variacoes v ON v.id_variacao = cv.id_variacao
     LEFT JOIN variacao_valores vv ON vv.id_variacao = v.id_variacao
     WHERE cv.id_categoria = :ic
     GROUP BY v.id_variacao, v.nome
     ORDER BY v.nome'
);
$stmtVariacoesCategoria->execute([':ic' => $produto['id_categoria']]);
$listaVariacoesCategoria = $stmtVariacoesCategoria->fetchAll();

$stmtVariacoesEmUso = $pdo->prepare(
    'SELECT DISTINCT v.id_variacao
     FROM produto_variacao_valores pvv
     JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     JOIN variacoes v ON v.id_variacao = vv.id_variacao
     JOIN produto_variacoes pv ON pv.id_produto_variacao = pvv.id_produto_variacao
     WHERE pv.id_produto = :ip'
);
$stmtVariacoesEmUso->execute([':ip' => $id_produto]);
$idsVariacoesEmUso = $stmtVariacoesEmUso->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Editar produto</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
</head>
<body>
<?php require __DIR__ . '/../includes/admin_header.php'; ?>
    <div class="page-title">
        <span class="icone-titulo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.4 12.2a2 2 0 0 0 2 1.8h7.2a2 2 0 0 0 2-1.6L20 8H6M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2Zm8 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
        <div>
            <h1><?= htmlspecialchars($produto['nome']) ?></h1>
            <span class="subtitulo"><?= htmlspecialchars($produto['categoria'] ?? '') ?> · <span class="status-pill<?= $produto['ativo'] ? ' sucesso' : ' erro' ?>"><?= $produto['ativo'] ? 'Ativo' : 'Inativo' ?></span></span>
        </div>
    </div>

    <p class="acoes-topo">
        <a href="/produtos/lista.php" class="btn-texto">← Voltar pra lista</a>
        <a href="<?= htmlspecialchars($linkCompartilharWhatsapp) ?>" target="_blank" rel="noopener" class="btn-compartilhar-whatsapp">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
            Compartilhar no WhatsApp
        </a>
    </p>

    <?php if (isset($_GET['atualizado'])): ?><p class="alert alert-sucesso">Produto atualizado.</p><?php endif; ?>
    <?php if (isset($_GET['criado'])): ?><p class="alert alert-sucesso">Produto criado com sucesso.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if (isset($_GET['upload_status']) && $_GET['upload_status'] === 'error'): ?>
        <p class="alert alert-erro">Falha ao enviar a foto (<?= htmlspecialchars($_GET['msg'] ?? 'erro desconhecido') ?>).</p>
    <?php endif; ?>
    <?php if (empty($listaFotos)): ?>
        <p class="alert alert-info">Adicione uma foto pra ela aparecer na prévia do link quando compartilhar e na vitrine da loja.</p>
    <?php endif; ?>

    <div class="card">
        <h2>Variações do produto</h2>
        <?php if (empty($listaVariacoesCategoria)): ?>
        <p class="alert alert-info">A categoria "<?= htmlspecialchars($produto['categoria']) ?>" ainda não tem variações cadastradas. <a href="/produtos/variacoes.php?id_categoria=<?= $produto['id_categoria'] ?>">Cadastrar variações</a>.</p>
        <?php else: ?>
        <?php if (isset($_GET['variacoes_atualizadas'])): ?><p class="alert alert-sucesso">Combinações atualizadas — defina estoque e preço delas ali embaixo, em "Combinações".</p><?php endif; ?>
        <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Marque as variações que esse produto usa. As combinações novas (inclusive de valores que você acabou de cadastrar na categoria) aparecem em "Combinações" prontas pra receber estoque e preço.</p>
        <form method="post" class="lista-checkbox">
            <input type="hidden" name="acao" value="sincronizar_variacoes">
            <?php foreach ($listaVariacoesCategoria as $v): ?>
            <?php $emUso = in_array((int) $v['id_variacao'], $idsVariacoesEmUso, true); ?>
            <label>
                <input type="checkbox" name="variacoes[]" value="<?= $v['id_variacao'] ?>" <?= $emUso ? 'checked disabled' : '' ?>>
                <?php if ($emUso): ?><input type="hidden" name="variacoes[]" value="<?= $v['id_variacao'] ?>"><?php endif; ?>
                <strong><?= htmlspecialchars($v['nome']) ?></strong> — <?= htmlspecialchars($v['valores'] ?? '') ?>
                <?php if ($emUso): ?><span class="status-pill sucesso">em uso</span><?php endif; ?>
            </label>
            <?php endforeach; ?>
            <button type="submit" class="btn-outline">Atualizar combinações</button>
        </form>
        <?php endif; ?>
    </div>

    <div class="grade-2col">
        <div class="card">
            <h2>Fotos (<?= count($listaFotos) ?>/<?= MAX_FOTOS_PRODUTO ?>)</h2>
            <div class="grade-fotos">
                <?php foreach ($listaFotos as $i => $f): ?>
                <div class="foto-item">
                    <?php if ($i === 0): ?><span class="foto-capa">Capa</span><?php endif; ?>
                    <img src="/<?= htmlspecialchars(fotoComVersao($f['caminho_arquivo'])) ?>" alt="Foto do produto">
                    <div class="foto-item-acoes">
                        <?php if ($i !== 0): ?>
                        <form method="post" action="/produtos/ajax/definir_capa.php">
                            <input type="hidden" name="id_foto" value="<?= $f['id_foto'] ?>">
                            <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
                            <button type="submit" class="btn-sm btn-outline">Tornar capa</button>
                        </form>
                        <?php endif; ?>
                        <form method="post" action="/produtos/ajax/deletar_foto.php" data-confirm="Remover esta foto?">
                            <input type="hidden" name="id_foto" value="<?= $f['id_foto'] ?>">
                            <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
                            <button type="submit" class="btn-sm btn-perigo btn-icone" title="Remover foto" aria-label="Remover foto">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3m-8 0 1 13a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-13M10 11v6M14 11v6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (count($listaFotos) < MAX_FOTOS_PRODUTO): ?>
                <button type="button" class="foto-item-adicionar" id="btn-abrir-upload">
                    <svg viewBox="0 0 24 24" aria-hidden="true" width="28" height="28"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                    <span>Adicionar foto</span>
                </button>
                <?php endif; ?>
            </div>
            <input type="file" id="input-nova-foto" accept="image/png,image/jpeg,image/webp" style="display:none;">
            <p id="msg-upload-foto" class="alert alert-erro" style="display:none; margin-top:14px;"></p>
            <p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:10px;">A primeira foto (Capa) é a que aparece no catálogo, no card do produto e na prévia de compartilhamento.</p>
        </div>

        <div class="card">
            <form method="post">
                <div class="card-cabecalho">
                    <h2>Dados do produto</h2>
                    <label class="toggle-switch" title="Visível na loja online. Desmarcado, fica escondido da vitrine sem apagar nada.">
                        <input type="checkbox" name="ativo" <?= $produto['ativo'] ? 'checked' : '' ?>>
                        <span class="toggle-slider"></span>
                        <span class="toggle-texto">Ativo</span>
                    </label>
                </div>
                <input type="hidden" name="acao" value="atualizar">
                <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($produto['nome']) ?>" required></label>
                <label>Descrição<textarea name="descricao"><?= htmlspecialchars($produto['descricao'] ?? '') ?></textarea></label>
                <label>Preço base (R$)<input type="text" name="preco_base" value="<?= number_format($produto['preco_base'], 2, ',', '') ?>" required></label>

                <h3>Combinações</h3>
                <div class="tabela-wrap">
                <table>
                    <tr><th>Combinação</th><th>Estoque</th><th>Preço (branco = usa o base)</th></tr>
                    <?php foreach ($listaCombinacoes as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['descricao'] ?? 'Padrão (sem variação)') ?></td>
                        <td><input type="number" min="0" name="estoque[<?= $c['id_produto_variacao'] ?>]" value="<?= (int) $c['estoque'] ?>"></td>
                        <td><input type="text" name="preco[<?= $c['id_produto_variacao'] ?>]" value="<?= $c['preco'] !== null ? number_format($c['preco'], 2, ',', '') : '' ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>

                <button type="submit" class="btn-bloco">Salvar alterações</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modal-recorte" hidden>
        <div class="modal-card">
            <h3>Ajustar foto</h3>
            <div class="area-corte"><img id="imagem-recorte" alt=""></div>
            <div class="modal-acoes">
                <button type="button" class="btn-outline" id="btn-cancelar-recorte">Cancelar</button>
                <button type="button" class="btn" id="btn-confirmar-recorte">Cortar e enviar</button>
            </div>
        </div>
    </div>

    <div class="card card-perigo">
        <h2>Zona de risco</h2>
        <p>Excluir este produto remove também todas as fotos dele, definitivamente. Essa ação não pode ser desfeita.</p>
        <form method="post" action="/produtos/ajax/deletar_produto.php" data-confirm="Excluir este produto e suas fotos definitivamente?">
            <input type="hidden" name="id_produto" value="<?= $id_produto ?>">
            <button type="submit" class="btn-perigo">Excluir produto</button>
        </form>
    </div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
<script>
(function () {
    const idProduto = <?= (int) $id_produto ?>;
    let cropper = null;

    const inputFoto = document.getElementById('input-nova-foto');
    const modal = document.getElementById('modal-recorte');
    const imagem = document.getElementById('imagem-recorte');
    const msgErro = document.getElementById('msg-upload-foto');

    document.getElementById('btn-abrir-upload')?.addEventListener('click', function () {
        inputFoto.click();
    });

    inputFoto?.addEventListener('change', function () {
        const arquivo = inputFoto.files && inputFoto.files[0];
        if (!arquivo) { return; }

        const leitor = new FileReader();
        leitor.onload = function (e) {
            imagem.src = e.target.result;
            modal.hidden = false;
            if (cropper) { cropper.destroy(); }
            cropper = new Cropper(imagem, {
                aspectRatio: 1,
                viewMode: 1,
                autoCropArea: 1,
                background: false,
            });
        };
        leitor.readAsDataURL(arquivo);
    });

    function fecharModalRecorte() {
        modal.hidden = true;
        if (cropper) { cropper.destroy(); cropper = null; }
        inputFoto.value = '';
    }

    document.getElementById('btn-cancelar-recorte').addEventListener('click', fecharModalRecorte);
    modal.addEventListener('click', function (e) { if (e.target === modal) { fecharModalRecorte(); } });

    document.getElementById('btn-confirmar-recorte').addEventListener('click', function () {
        if (!cropper) { return; }
        const botao = this;
        botao.disabled = true;
        msgErro.style.display = 'none';

        cropper.getCroppedCanvas({ width: 500, height: 500 }).toBlob(function (blob) {
            const formData = new FormData();
            formData.append('id_produto', idProduto);
            formData.append('foto', blob, 'foto.jpg');

            fetch('/produtos/ajax/upload_foto.php', { method: 'POST', body: formData })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        msgErro.textContent = data.message || 'Erro ao enviar a foto.';
                        msgErro.style.display = '';
                        botao.disabled = false;
                    }
                })
                .catch(function () {
                    msgErro.textContent = 'Erro de conexão. Tente novamente.';
                    msgErro.style.display = '';
                    botao.disabled = false;
                })
                .finally(function () { fecharModalRecorte(); });
        }, 'image/jpeg', 0.9);
    });
})();
</script>
</main>
</body>
</html>
