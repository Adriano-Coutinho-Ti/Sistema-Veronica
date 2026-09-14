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

// Gera um novo código de 3 dígitos e SOBRESCREVE o que já existir — ação
// explícita do lojista a partir do modal (deixou de ser só um backfill
// automático "apenas se vazio" quando o modal ganhou a opção manual).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'gerar_codigo') {
    $novoCodigo = gerarCodigoProdutoUnico($pdo);
    $pdo->prepare('UPDATE produtos SET codigo = :c WHERE id_produto = :id')
        ->execute([':c' => $novoCodigo, ':id' => $id_produto]);
    $produto['codigo'] = $novoCodigo;
    header('Location: /produtos/editar.php?id=' . $id_produto . '&codigo_gerado=1');
    exit;
}

// Alternativa ao código de 3 dígitos gerado pelo sistema: o lojista digita
// (ou escaneia com um leitor de código de barras, que funciona como
// teclado) o código de barras original da peça.
$erroCodigo = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'definir_codigo_manual') {
    $codigoManual = trim($_POST['codigo_manual'] ?? '');
    if ($codigoManual === '') {
        $erroCodigo = 'Informe um código.';
    } elseif (in_array($codigoManual, CODIGOS_PRODUTO_BLOQUEADOS, true)) {
        $erroCodigo = 'Esse código não pode ser usado.';
    } else {
        $existe = $pdo->prepare('SELECT id_produto FROM produtos WHERE codigo = :c AND id_produto != :id');
        $existe->execute([':c' => $codigoManual, ':id' => $id_produto]);
        if ($existe->fetch()) {
            $erroCodigo = 'Esse código já está sendo usado por outro produto.';
        } else {
            $pdo->prepare('UPDATE produtos SET codigo = :c WHERE id_produto = :id')
                ->execute([':c' => $codigoManual, ':id' => $id_produto]);
            header('Location: /produtos/editar.php?id=' . $id_produto . '&codigo_gerado=1');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_dados') {
    $nome = trim($_POST['nome'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '') ?: null;
    $preco_base = (float) str_replace(',', '.', $_POST['preco_base'] ?? '0');
    $ativo = isset($_POST['ativo']) ? 1 : 0;
    $estoqueGerenciado = isset($_POST['estoque_gerenciado']) ? 1 : 0;

    if ($nome === '' || $preco_base <= 0) {
        $erro = 'Nome e preço base são obrigatórios.';
    } else {
        $pdo->prepare('UPDATE produtos SET nome = :nome, descricao = :descricao, preco_base = :preco_base, ativo = :ativo, estoque_gerenciado = :eg WHERE id_produto = :id')
            ->execute([':nome' => $nome, ':descricao' => $descricao, ':preco_base' => $preco_base, ':ativo' => $ativo, ':eg' => $estoqueGerenciado, ':id' => $id_produto]);

        header('Location: /produtos/editar.php?id=' . $id_produto . '&atualizado=1');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_combinacoes') {
    foreach ($_POST['estoque'] ?? [] as $id_pv => $valor) {
        $pdo->prepare('UPDATE produto_variacoes SET estoque = :estoque WHERE id_produto_variacao = :id AND id_produto = :ip')
            ->execute([':estoque' => (int) $valor, ':id' => (int) $id_pv, ':ip' => $id_produto]);
    }
    foreach ($_POST['preco'] ?? [] as $id_pv => $valor) {
        $precoCombinacao = $valor === '' ? null : (float) str_replace(',', '.', $valor);
        $pdo->prepare('UPDATE produto_variacoes SET preco = :preco WHERE id_produto_variacao = :id AND id_produto = :ip')
            ->execute([':preco' => $precoCombinacao, ':id' => (int) $id_pv, ':ip' => $id_produto]);
    }

    header('Location: /produtos/editar.php?id=' . $id_produto . '&combinacoes_atualizadas=1');
    exit;
}

// Sincroniza produto_variacoes com os VALORES marcados (não a variação
// inteira — o lojista escolhe exatamente quais cores/tamanhos esse produto
// usa). O checkbox é a fonte da verdade: cria as combinações que faltam e
// REMOVE as que existiam mas foram desmarcadas — sem nada marcado em
// nenhuma variação, sobra só a combinação "Padrão (sem variação)". Uma
// combinação só não é removida se estiver reservada agora no carrinho de
// um cliente (estoque_reservado > 0) — nesse caso ela fica intacta e o
// lojista é avisado, pra não quebrar uma compra em andamento.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'sincronizar_variacoes') {
    $idsValorSelecionados = array_unique(array_filter(array_map('intval', $_POST['valores'] ?? [])));

    $gruposValores = [];
    if ($idsValorSelecionados) {
        $placeholders = implode(',', array_fill(0, count($idsValorSelecionados), '?'));
        $stmtValoresPorVariacao = $pdo->prepare(
            "SELECT vv.id_variacao, vv.id_valor FROM variacao_valores vv
             JOIN categoria_variacoes cv ON cv.id_variacao = vv.id_variacao
             WHERE vv.id_valor IN ($placeholders) AND cv.id_categoria = ?
             ORDER BY vv.valor"
        );
        $stmtValoresPorVariacao->execute([...$idsValorSelecionados, $produto['id_categoria']]);
        foreach ($stmtValoresPorVariacao->fetchAll() as $linha) {
            $gruposValores[(int) $linha['id_variacao']][] = (int) $linha['id_valor'];
        }
        $gruposValores = array_values($gruposValores);
    }

    // Produto cartesiano dos valores marcados. Sem nenhum valor marcado, o
    // resultado fica [[]] — a própria combinação "Padrão (sem variação)".
    $combinacoesDesejadas = [[]];
    foreach ($gruposValores as $grupo) {
        $novasCombinacoes = [];
        foreach ($combinacoesDesejadas as $combinacaoAtual) {
            foreach ($grupo as $idValor) {
                $novasCombinacoes[] = array_merge($combinacaoAtual, [$idValor]);
            }
        }
        $combinacoesDesejadas = $novasCombinacoes;
    }

    $chavesDesejadas = [];
    foreach ($combinacoesDesejadas as $combinacao) {
        sort($combinacao);
        $chavesDesejadas[implode(',', $combinacao)] = $combinacao;
    }

    $stmtExistentes = $pdo->prepare(
        'SELECT pv.id_produto_variacao, pv.estoque_reservado,
                GROUP_CONCAT(pvv.id_valor ORDER BY pvv.id_valor SEPARATOR ",") AS chave
         FROM produto_variacoes pv
         LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
         WHERE pv.id_produto = :ip
         GROUP BY pv.id_produto_variacao, pv.estoque_reservado'
    );
    $stmtExistentes->execute([':ip' => $id_produto]);
    $existentes = [];
    foreach ($stmtExistentes->fetchAll() as $linha) {
        $existentes[$linha['chave'] ?? ''] = $linha;
    }

    foreach ($chavesDesejadas as $chave => $combinacao) {
        if (isset($existentes[$chave])) {
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

    $existeReservaBloqueada = false;
    foreach ($existentes as $chave => $linha) {
        if (isset($chavesDesejadas[$chave])) {
            continue;
        }
        if ((int) $linha['estoque_reservado'] > 0) {
            $existeReservaBloqueada = true;
            continue;
        }
        $pdo->prepare('DELETE FROM produto_variacoes WHERE id_produto_variacao = :id AND id_produto = :ip')
            ->execute([':id' => $linha['id_produto_variacao'], ':ip' => $id_produto]);
    }

    $parametroRedirect = $existeReservaBloqueada ? 'variacoes_parcial=1' : 'variacoes_atualizadas=1';
    header('Location: /produtos/editar.php?id=' . $id_produto . '&' . $parametroRedirect);
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

// Agrupado por variação (Cor, Tamanho...) pra desenhar uma caixa por grupo
// no popup, cada uma com um checkbox por VALOR — o lojista escolhe exatamente
// quais valores esse produto usa, não a variação toda de uma vez.
$stmtValoresCategoria = $pdo->prepare(
    'SELECT v.id_variacao, v.nome AS nome_variacao, vv.id_valor, vv.valor
     FROM categoria_variacoes cv
     JOIN variacoes v ON v.id_variacao = cv.id_variacao
     JOIN variacao_valores vv ON vv.id_variacao = v.id_variacao
     WHERE cv.id_categoria = :ic
     ORDER BY v.nome, vv.valor'
);
$stmtValoresCategoria->execute([':ic' => $produto['id_categoria']]);
$gruposVariacaoCategoria = [];
foreach ($stmtValoresCategoria->fetchAll() as $linha) {
    $gruposVariacaoCategoria[$linha['nome_variacao']][] = $linha;
}

$stmtValoresEmUso = $pdo->prepare(
    'SELECT DISTINCT pvv.id_valor
     FROM produto_variacao_valores pvv
     JOIN produto_variacoes pv ON pv.id_produto_variacao = pvv.id_produto_variacao
     WHERE pv.id_produto = :ip'
);
$stmtValoresEmUso->execute([':ip' => $id_produto]);
$idsValoresEmUso = $stmtValoresEmUso->fetchAll(PDO::FETCH_COLUMN);
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
        <?php if (!empty($produto['codigo'])): ?>
        <span class="codigo-produto"><span class="codigo-rotulo">Código</span> <?= htmlspecialchars($produto['codigo']) ?></span>
        <button type="button" class="btn-sm btn-outline" id="btn-abrir-codigo">Editar código</button>
        <?php else: ?>
        <button type="button" class="btn-outline btn-sm" id="btn-abrir-codigo" style="margin-left:auto;">Definir código do produto</button>
        <?php endif; ?>
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
    <?php if (isset($_GET['codigo_gerado'])): ?><p class="alert alert-sucesso">Código <strong><?= htmlspecialchars($produto['codigo']) ?></strong> definido pra este produto.</p><?php endif; ?>
    <?php if (isset($_GET['variacoes_atualizadas'])): ?><p class="alert alert-sucesso">Variações atualizadas — defina estoque e preço das combinações novas em "Combinações".</p><?php endif; ?>
    <?php if (isset($_GET['variacoes_parcial'])): ?><p class="alert alert-erro">Variações atualizadas, mas uma ou mais combinações desmarcadas não foram removidas por estarem reservadas agora no carrinho de um cliente — tente de novo daqui a pouco.</p><?php endif; ?>
    <?php if (isset($_GET['combinacoes_atualizadas'])): ?><p class="alert alert-sucesso">Combinações salvas.</p><?php endif; ?>
    <?php if ($erro): ?><p class="alert alert-erro"><?= htmlspecialchars($erro) ?></p><?php endif; ?>
    <?php if (isset($_GET['upload_status']) && $_GET['upload_status'] === 'error'): ?>
        <p class="alert alert-erro">Falha ao enviar a foto (<?= htmlspecialchars($_GET['msg'] ?? 'erro desconhecido') ?>).</p>
    <?php endif; ?>
    <?php if (empty($listaFotos)): ?>
        <p class="alert alert-info">Adicione uma foto pra ela aparecer na prévia do link quando compartilhar e na vitrine da loja.</p>
    <?php endif; ?>

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
                    <div style="display:flex; gap:14px; flex-wrap:wrap;">
                        <label class="toggle-switch" title="Visível na loja online. Desmarcado, fica escondido da vitrine sem apagar nada.">
                            <input type="checkbox" name="ativo" <?= $produto['ativo'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                            <span class="toggle-texto">Ativo</span>
                        </label>
                        <label class="toggle-switch" title="Desmarcado, este produto fica sempre disponível pra venda (loja online e PDV), sem checar nem descontar estoque.">
                            <input type="checkbox" name="estoque_gerenciado" <?= $produto['estoque_gerenciado'] ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                            <span class="toggle-texto">Controlar estoque</span>
                        </label>
                    </div>
                </div>
                <input type="hidden" name="acao" value="atualizar_dados">
                <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($produto['nome']) ?>" required></label>
                <label>Descrição<textarea name="descricao"><?= htmlspecialchars($produto['descricao'] ?? '') ?></textarea></label>
                <label>Preço base (R$)<input type="text" name="preco_base" value="<?= number_format($produto['preco_base'], 2, ',', '') ?>" required></label>

                <button type="submit" class="btn-bloco">Salvar dados do produto</button>
            </form>

            <h3 style="margin-top:28px;">Variações do produto</h3>
            <?php if (empty($gruposVariacaoCategoria)): ?>
            <p class="alert alert-info">A categoria "<?= htmlspecialchars($produto['categoria']) ?>" ainda não tem variações cadastradas. <a href="/produtos/variacoes.php?id_categoria=<?= $produto['id_categoria'] ?>">Cadastrar variações</a>.</p>
            <?php else: ?>
            <button type="button" class="btn-outline" id="btn-abrir-variacoes">Editar Variações do produto</button>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="acao" value="atualizar_combinacoes">
                <h3 style="margin-top:28px;">Combinações</h3>
                <?php if (!$produto['estoque_gerenciado']): ?>
                <p class="alert alert-info">Estoque não controlado — este produto pode ser vendido livremente, sem limite de quantidade.</p>
                <?php endif; ?>
                <div class="tabela-wrap">
                <table>
                    <tr><th>Combinação</th><?php if ($produto['estoque_gerenciado']): ?><th>Estoque</th><?php endif; ?><th>Preço (branco = usa o base)</th></tr>
                    <?php foreach ($listaCombinacoes as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['descricao'] ?? 'Padrão (sem variação)') ?></td>
                        <?php if ($produto['estoque_gerenciado']): ?>
                        <td><input type="number" min="0" name="estoque[<?= $c['id_produto_variacao'] ?>]" value="<?= (int) $c['estoque'] ?>"></td>
                        <?php endif; ?>
                        <td><input type="text" name="preco[<?= $c['id_produto_variacao'] ?>]" value="<?= $c['preco'] !== null ? number_format($c['preco'], 2, ',', '') : '' ?>"></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
                </div>

                <button type="submit" class="btn-bloco">Salvar combinações</button>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modal-variacoes" hidden>
        <div class="modal-card modal-card-lg">
            <h3>Variações do produto</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Marque os valores que esse produto usa. As combinações aparecem em "Combinações" prontas pra receber estoque e preço. Desmarcar um valor remove a combinação correspondente (e o estoque cadastrado nela, se houver) — sem nada marcado, sobra só a combinação Padrão.</p>
            <form method="post" id="form-variacoes" data-confirm="Atualizar as variações? Valores desmarcados vão remover as combinações correspondentes, junto com o estoque cadastrado nelas.">
                <input type="hidden" name="acao" value="sincronizar_variacoes">
                <div class="lista-grupos-variacao">
                    <?php foreach ($gruposVariacaoCategoria as $nomeVariacao => $valoresDoGrupo): ?>
                    <div class="grupo-variacao">
                        <h4><?= htmlspecialchars($nomeVariacao) ?></h4>
                        <div class="valores-checkbox">
                            <?php foreach ($valoresDoGrupo as $vv): ?>
                            <?php $emUso = in_array((int) $vv['id_valor'], $idsValoresEmUso, true); ?>
                            <label>
                                <input type="checkbox" name="valores[]" value="<?= $vv['id_valor'] ?>" <?= $emUso ? 'checked' : '' ?>>
                                <?= htmlspecialchars($vv['valor']) ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-variacoes">Cancelar</button>
                    <button type="submit" class="btn">Atualizar combinações</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="modal-codigo" <?= $erroCodigo ? '' : 'hidden' ?>>
        <div class="modal-card">
            <h3>Código do produto</h3>
            <p style="color:var(--cor-texto-suave); font-size:0.9rem; margin-bottom:14px;">Usado na busca rápida do PDV e nas etiquetas físicas. Pode ser o código de 3 dígitos gerado pelo sistema ou o código de barras original da peça — digite ou escaneie com o leitor.</p>
            <?php if ($erroCodigo): ?><p class="alert alert-erro"><?= htmlspecialchars($erroCodigo) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="acao" value="definir_codigo_manual">
                <label>Código<input type="text" name="codigo_manual" value="<?= htmlspecialchars($codigoManual ?? $produto['codigo'] ?? '') ?>" placeholder="Digite ou escaneie um código de barras" maxlength="30"></label>
                <div class="modal-acoes">
                    <button type="button" class="btn-outline" id="btn-cancelar-codigo">Cancelar</button>
                    <button type="submit" class="btn">Salvar código</button>
                </div>
            </form>
            <form method="post" style="margin-top:14px; border-top:1px solid var(--cor-borda); padding-top:14px;">
                <input type="hidden" name="acao" value="gerar_codigo">
                <button type="submit" class="btn-outline btn-bloco">Gerar código automático (3 dígitos)</button>
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
    const modalCodigo = document.getElementById('modal-codigo');
    if (modalCodigo) {
        function fecharModalCodigo() { modalCodigo.hidden = true; }
        document.getElementById('btn-abrir-codigo')?.addEventListener('click', function () {
            modalCodigo.hidden = false;
        });
        document.getElementById('btn-cancelar-codigo').addEventListener('click', fecharModalCodigo);
        modalCodigo.addEventListener('click', function (e) { if (e.target === modalCodigo) { fecharModalCodigo(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modalCodigo.hidden) { fecharModalCodigo(); } });
    }
})();

(function () {
    const modalVariacoes = document.getElementById('modal-variacoes');
    if (modalVariacoes) {
        function fecharModalVariacoes() { modalVariacoes.hidden = true; }
        document.getElementById('btn-abrir-variacoes')?.addEventListener('click', function () {
            modalVariacoes.hidden = false;
        });
        document.getElementById('btn-cancelar-variacoes').addEventListener('click', fecharModalVariacoes);
        modalVariacoes.addEventListener('click', function (e) { if (e.target === modalVariacoes) { fecharModalVariacoes(); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modalVariacoes.hidden) { fecharModalVariacoes(); } });
    }
})();

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
