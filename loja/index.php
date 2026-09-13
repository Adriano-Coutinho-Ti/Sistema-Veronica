<?php
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth_cliente.php';
require_once __DIR__ . '/../includes/loja.php';

liberarReservasExpiradas($pdo);

$id_categoria = (int) ($_GET['categoria'] ?? 0);
$busca = trim($_GET['busca'] ?? '');

$categorias = $pdo->query('SELECT id_categoria, nome FROM categorias ORDER BY nome')->fetchAll();

const PRODUTOS_POR_PAGINA = 12;
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$filtros = '';
$params = [];
if ($id_categoria > 0) {
    $filtros .= ' AND p.id_categoria = :ic';
    $params[':ic'] = $id_categoria;
}
if ($busca !== '') {
    $filtros .= ' AND p.nome LIKE :busca';
    $params[':busca'] = '%' . $busca . '%';
}

// Produto com estoque físico > 0 continua listado mesmo se, no momento, tudo já
// estiver reservado em carrinhos de outros clientes — a reserva pode expirar ou o
// pagamento pode falhar, e o item volta a ficar comprável. Só some da vitrine de
// verdade quando o estoque físico realmente zera (venda concluída). O card mostra
// a tag "Em um carrinho" nesse caso intermediário — ver $reservado mais abaixo.
$sqlTotal = "SELECT COUNT(*) FROM (
    SELECT p.id_produto
    FROM produtos p
    JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
    WHERE p.ativo = 1 $filtros
    GROUP BY p.id_produto
    HAVING SUM(pv.estoque) > 0
) t";
$stmtTotal = $pdo->prepare($sqlTotal);
$stmtTotal->execute($params);
$totalProdutos = (int) $stmtTotal->fetchColumn();
$totalPaginas = max(1, (int) ceil($totalProdutos / PRODUTOS_POR_PAGINA));
$pagina = min($pagina, $totalPaginas);
$offset = ($pagina - 1) * PRODUTOS_POR_PAGINA;

$sql = "SELECT p.id_produto, p.nome, p.preco_base,
               SUM(pv.estoque - pv.estoque_reservado) AS disponivel,
               SUM(pv.estoque) AS estoque_fisico
        FROM produtos p
        JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE p.ativo = 1 $filtros
        GROUP BY p.id_produto, p.nome, p.preco_base
        HAVING estoque_fisico > 0
        ORDER BY p.nome
        LIMIT :limite OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $chave => $valor) {
    $stmt->bindValue($chave, $valor);
}
$stmt->bindValue(':limite', PRODUTOS_POR_PAGINA, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$produtos = $stmt->fetchAll();

// IDs vindos do redirecionamento do carrinho quando o cronômetro zera — ficam
// destacados como "nova oportunidade" pra quem chegou aqui (e pra qualquer um
// que reabra esse link).
$voltouIds = [];
if (!empty($_GET['voltou'])) {
    $voltouIds = array_filter(array_map('intval', explode(',', $_GET['voltou'])));
}

$temReservadoNaPagina = false;
$temVoltouNaPagina = false;
foreach ($produtos as $p) {
    if ((int) $p['disponivel'] <= 0) {
        $temReservadoNaPagina = true;
    }
    if (in_array((int) $p['id_produto'], $voltouIds, true)) {
        $temVoltouNaPagina = true;
    }
}

$fotosPorProduto = [];
if (!empty($produtos)) {
    $ids = array_column($produtos, 'id_produto');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtFotos = $pdo->prepare(
        "SELECT id_produto, caminho_arquivo FROM produto_fotos WHERE id_produto IN ($placeholders) ORDER BY id_produto, ordem"
    );
    $stmtFotos->execute($ids);
    foreach ($stmtFotos->fetchAll() as $f) {
        $fotosPorProduto[(int) $f['id_produto']][] = $f['caminho_arquivo'];
    }
}

$favoritoIds = [];
if (!empty($_SESSION['id_cliente'])) {
    $stmtFav = $pdo->prepare('SELECT id_produto FROM favoritos WHERE id_cliente = :ic');
    $stmtFav->execute([':ic' => (int) $_SESSION['id_cliente']]);
    $favoritoIds = array_map('intval', $stmtFav->fetchAll(PDO::FETCH_COLUMN));
}

// Instante de referência pro poll de novidades (loja/ajax/verificar_novidades.php) —
// vem do MySQL, não do PHP, pra bater com o mesmo relógio usado em liberado_em.
$agoraServidor = $pdo->query('SELECT NOW()')->fetchColumn();

function montarLinkPagina(int $p, int $categoria, string $busca): string
{
    $params = ['pagina' => $p];
    if ($categoria > 0) {
        $params['categoria'] = $categoria;
    }
    if ($busca !== '') {
        $params['busca'] = $busca;
    }
    return '/loja/index.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Loja</title></head>
<body>
<?php require __DIR__ . '/../includes/loja_header.php'; ?>
    <div class="banner-hero">
        <h1>Catálogo</h1>
        <p>Peças selecionadas com carinho — cada compra dá uma segunda vida a algo especial.</p>
    </div>

    <div id="banner-oportunidade">
        <?php if (!empty($voltouIds)): ?>
        <p class="alert alert-oportunidade"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 2a8 8 0 1 1 0 16 8 8 0 0 1 0-16Zm-1 3v6l5 3 1-1.6-4-2.4V7Z"/></svg> Seu tempo pra pagar acabou — os itens do seu carrinho voltaram pra loja e já estão disponíveis de novo (inclusive pra outros clientes).</p>
        <?php endif; ?>
    </div>

    <form class="busca-catalogo" method="get">
        <?php if ($id_categoria > 0): ?><input type="hidden" name="categoria" value="<?= $id_categoria ?>"><?php endif; ?>
        <input type="text" name="busca" placeholder="Buscar produtos..." value="<?= htmlspecialchars($busca) ?>">
        <button type="submit" aria-label="Buscar">
            <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4a6 6 0 1 0 3.76 10.66l4.79 4.79 1.41-1.41-4.79-4.79A6 6 0 0 0 10 4Zm-4 6a4 4 0 1 1 8 0 4 4 0 0 1-8 0Z"/></svg>
        </button>
    </form>

    <div class="catalogo-layout">
        <aside class="categorias-lateral">
            <h2>Categorias</h2>
            <nav>
                <a href="/loja/index.php" class="<?= $id_categoria === 0 ? 'ativa' : '' ?>">Todas</a>
                <?php foreach ($categorias as $c): ?>
                    <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>" class="<?= $id_categoria === (int) $c['id_categoria'] ? 'ativa' : '' ?>">
                        <?= htmlspecialchars($c['nome']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div>
            <div class="categorias">
                <a href="/loja/index.php" class="categoria-pill<?= $id_categoria === 0 ? ' ativa' : '' ?>">Todas</a>
                <?php foreach ($categorias as $c): ?>
                    <a href="/loja/index.php?categoria=<?= $c['id_categoria'] ?>" class="categoria-pill<?= $id_categoria === (int) $c['id_categoria'] ? ' ativa' : '' ?>">
                        <?= htmlspecialchars($c['nome']) ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($busca !== ''): ?>
                <p class="resultado-busca"><?= $totalProdutos ?> resultado<?= $totalProdutos === 1 ? '' : 's' ?> para "<?= htmlspecialchars($busca) ?>"</p>
            <?php endif; ?>

            <p class="alert alert-info" id="aviso-reservado"<?= $temReservadoNaPagina ? '' : ' hidden' ?>>Itens com a tag "Em um carrinho" estão reservados no carrinho de outro cliente, mas ainda não foram pagos — podem voltar a ficar disponíveis a qualquer momento.</p>

            <p class="alert alert-oportunidade" id="aviso-voltou-geral"<?= $temVoltouNaPagina ? '' : ' hidden' ?>><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg> Peça(s) que estavam no carrinho de outro cliente voltaram pra loja — aproveite antes que sumam de novo!</p>

            <div class="product-grid" id="grade-produtos">
                <?php foreach ($produtos as $p): ?>
                <?php
                    $fotos = $fotosPorProduto[(int) $p['id_produto']] ?? [];
                    $ehOportunidade = in_array((int) $p['id_produto'], $voltouIds, true);
                    $disp = (int) $p['disponivel'];
                    $reservado = $disp <= 0;
                    $ehFavorito = in_array((int) $p['id_produto'], $favoritoIds, true);
                    $linkWhatsappCard = montarLinkCompartilharWhatsapp($p['nome'], (float) $p['preco_base'], 'https://brechodaveve.codernex.com.br/loja/produto.php?id=' . $p['id_produto']);
                ?>
                <a href="/loja/produto.php?id=<?= $p['id_produto'] ?>" class="product-card<?= $ehOportunidade ? ' voltou' : '' ?><?= $reservado ? ' reservado' : '' ?>" data-id-produto="<?= $p['id_produto'] ?>">
                    <?php if ($ehOportunidade): ?><span class="tag-oportunidade"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg> Nova oportunidade</span>
                    <?php elseif ($reservado): ?><span class="tag-reservado">Em um carrinho</span><?php endif; ?>
                    <div class="card-acoes">
                        <button type="button" class="botao-acao favoritar<?= $ehFavorito ? ' ativo' : '' ?>" data-id-produto="<?= $p['id_produto'] ?>" aria-label="<?= $ehFavorito ? 'Remover dos favoritos' : 'Adicionar aos favoritos' ?>">
                            <svg class="icon-coracao" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.3 8.9 1.5 5 5 4c2.4-.7 4.8.3 6.2 2.3L12 7.6l.8-1.3C14.2 4.3 16.6 3.3 19 4c3.5 1 4.7 4.9 3 7.9-2.5 4.5-10 9.1-10 9.1Z"/></svg>
                        </button>
                        <button type="button" class="botao-acao compartilhar" data-whatsapp-link="<?= htmlspecialchars($linkWhatsappCard) ?>" aria-label="Compartilhar no WhatsApp">
                            <svg class="icon-whatsapp" viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg>
                        </button>
                    </div>
                    <div class="card-media">
                        <?php if (count($fotos) > 1): ?>
                        <div class="carousel" data-carousel data-carousel-auto="2000">
                            <div class="carousel-track">
                                <?php foreach ($fotos as $foto): ?>
                                    <img src="/<?= htmlspecialchars(fotoComVersao($foto)) ?>" alt="<?= htmlspecialchars($p['nome']) ?>">
                                <?php endforeach; ?>
                            </div>
                            <div class="carousel-dots">
                                <?php foreach ($fotos as $i => $foto): ?>
                                    <button type="button" class="dot<?= $i === 0 ? ' ativo' : '' ?>" data-index="<?= $i ?>" aria-label="Foto <?= $i + 1 ?>"></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php elseif (count($fotos) === 1): ?>
                            <img src="/<?= htmlspecialchars(fotoComVersao($fotos[0])) ?>" alt="<?= htmlspecialchars($p['nome']) ?>">
                        <?php endif; ?>
                    </div>
                    <div class="nome"><?= htmlspecialchars($p['nome']) ?></div>
                    <div class="price">R$ <?= number_format($p['preco_base'], 2, ',', '.') ?></div>
                    <?php if ($reservado): ?>
                        <div class="disponibilidade card disponibilidade-baixa">Aguardando pagamento de outro cliente</div>
                    <?php else: ?>
                        <div class="disponibilidade card<?= $disp <= 3 ? ' disponibilidade-baixa' : '' ?>"><?= $disp ?> disponíve<?= $disp === 1 ? 'l' : 'is' ?></div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
                <?php if (empty($produtos)): ?>
                    <p>Nenhum produto encontrado.</p>
                <?php endif; ?>
            </div>

            <?php if ($totalPaginas > 1): ?>
            <nav class="paginacao">
                <?php if ($pagina > 1): ?><a href="<?= montarLinkPagina($pagina - 1, $id_categoria, $busca) ?>">‹ Anterior</a><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPaginas; $p++): ?>
                    <a href="<?= montarLinkPagina($p, $id_categoria, $busca) ?>" class="<?= $p === $pagina ? 'ativa' : '' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($pagina < $totalPaginas): ?><a href="<?= montarLinkPagina($pagina + 1, $id_categoria, $busca) ?>">Próxima ›</a><?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
    </div>

<script>
(function () {
    let ultimaChecagem = <?= json_encode($agoraServidor) ?>;
    const categoriaAtual = <?= (int) $id_categoria ?>;
    let avisoTimeout = null;

    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    function criarCardProduto(produto) {
        const precoFormatado = produto.preco_base.toFixed(2).replace('.', ',');
        let mediaHtml = '';
        if (produto.fotos.length > 1) {
            const imgs = produto.fotos.map(function (f) { return '<img src="/' + escaparHtml(f) + '" alt="' + escaparHtml(produto.nome) + '">'; }).join('');
            const dots = produto.fotos.map(function (f, i) { return '<button type="button" class="dot' + (i === 0 ? ' ativo' : '') + '" data-index="' + i + '"></button>'; }).join('');
            mediaHtml = '<div class="carousel" data-carousel data-carousel-auto="2000"><div class="carousel-track">' + imgs + '</div><div class="carousel-dots">' + dots + '</div></div>';
        } else if (produto.fotos.length === 1) {
            mediaHtml = '<img src="/' + escaparHtml(produto.fotos[0]) + '" alt="' + escaparHtml(produto.nome) + '">';
        }

        const a = document.createElement('a');
        a.href = '/loja/produto.php?id=' + produto.id_produto;
        a.className = 'product-card voltou';
        a.dataset.idProduto = String(produto.id_produto);
        const dispClasse = produto.disponivel <= 3 ? ' disponibilidade-baixa' : '';
        const dispTexto = produto.disponivel + (produto.disponivel === 1 ? ' disponível' : ' disponíveis');
        const textoWhatsapp = '🛍️ *' + produto.nome + '*\nR$ ' + precoFormatado + '\n\nhttps://brechodaveve.codernex.com.br/loja/produto.php?id=' + produto.id_produto;
        const linkWhatsapp = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(textoWhatsapp);
        a.innerHTML =
            '<span class="tag-oportunidade"><svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg> Nova oportunidade</span>' +
            '<div class="card-acoes">' +
                '<button type="button" class="botao-acao favoritar" data-id-produto="' + produto.id_produto + '" aria-label="Adicionar aos favoritos"><svg class="icon-coracao" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.3 8.9 1.5 5 5 4c2.4-.7 4.8.3 6.2 2.3L12 7.6l.8-1.3C14.2 4.3 16.6 3.3 19 4c3.5 1 4.7 4.9 3 7.9-2.5 4.5-10 9.1-10 9.1Z"/></svg></button>' +
                '<button type="button" class="botao-acao compartilhar" data-whatsapp-link="' + escaparHtml(linkWhatsapp) + '" aria-label="Compartilhar no WhatsApp"><svg class="icon-whatsapp" viewBox="0 0 24 24" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.64-1.03-5.12-2.9-6.98A9.82 9.82 0 0 0 12.04 2Zm0 1.67c2.19 0 4.25.85 5.8 2.4a8.2 8.2 0 0 1 2.4 5.83c0 4.54-3.7 8.23-8.24 8.23a8.2 8.2 0 0 1-4.19-1.15l-.3-.18-3.12.82.83-3.04-.2-.31a8.18 8.18 0 0 1-1.26-4.37c0-4.54 3.7-8.23 8.24-8.23h.04Zm-4.6 4.2c-.16 0-.42.06-.64.31-.22.25-.85.83-.85 2.02s.87 2.35.99 2.51c.12.16 1.7 2.7 4.2 3.68 2.07.82 2.49.66 2.94.62.45-.04 1.45-.59 1.65-1.16.2-.57.2-1.06.14-1.16-.06-.1-.22-.16-.46-.28-.24-.12-1.45-.72-1.68-.8-.22-.08-.39-.12-.55.12-.16.24-.63.8-.77.96-.14.16-.28.18-.52.06-.24-.12-1.02-.38-1.94-1.2-.72-.64-1.2-1.44-1.34-1.68-.14-.24-.02-.37.1-.49.11-.11.24-.28.36-.42.12-.14.16-.24.24-.4.08-.16.04-.3-.02-.42-.06-.12-.55-1.35-.76-1.85-.2-.48-.4-.42-.55-.42Z"/></svg></button>' +
            '</div>' +
            '<div class="card-media">' + mediaHtml + '</div>' +
            '<div class="nome">' + escaparHtml(produto.nome) + '</div>' +
            '<div class="price">R$ ' + precoFormatado + '</div>' +
            '<div class="disponibilidade card' + dispClasse + '">' + dispTexto + '</div>';
        return a;
    }

    // Corrige o estado de UM cartão pra bater com a disponibilidade real, sempre —
    // nunca fica "meio atualizado". Chamado em toda checagem, pra todo cartão que
    // já está na tela, então um cartão nunca fica preso mostrando "em um carrinho"
    // depois de já ter sido liberado (nem o contrário, se alguém acabou de
    // reservar o que restava). É essa reafirmação total, e não um "avisa só o que
    // mudou", que garante 100% de acerto mesmo se uma rodada de poll falhar.
    function sincronizarCard(card, disponivel) {
        const reservado = disponivel <= 0;
        card.classList.toggle('reservado', reservado);

        let tagReservado = card.querySelector('.tag-reservado');
        if (reservado) {
            // Alguém já colocou de novo no carrinho antes da pessoa aproveitar —
            // "Em um carrinho" manda mais que "Nova oportunidade" agora, então a
            // festa dourada sai e a tag cinza toma o lugar dela.
            card.classList.remove('voltou');
            const tagOportunidade = card.querySelector('.tag-oportunidade');
            if (tagOportunidade) { tagOportunidade.remove(); }
            if (!tagReservado) {
                tagReservado = document.createElement('span');
                tagReservado.className = 'tag-reservado';
                tagReservado.textContent = 'Em um carrinho';
                card.prepend(tagReservado);
            }
        } else if (tagReservado) {
            tagReservado.remove();
        }

        const dispEl = card.querySelector('.disponibilidade.card');
        if (dispEl) {
            if (reservado) {
                dispEl.className = 'disponibilidade card disponibilidade-baixa';
                dispEl.textContent = 'Aguardando pagamento de outro cliente';
            } else {
                dispEl.className = 'disponibilidade card' + (disponivel <= 3 ? ' disponibilidade-baixa' : '');
                dispEl.textContent = disponivel + (disponivel === 1 ? ' disponível' : ' disponíveis');
            }
        }
    }

    let popupTimeout = null;
    function mostrarPopupOportunidade(produto) {
        let popup = document.getElementById('toast-oportunidade');
        if (popup) { popup.remove(); }

        popup = document.createElement('div');
        popup.className = 'toast-oportunidade';
        popup.id = 'toast-oportunidade';
        const foto = produto.fotos && produto.fotos.length ? '/' + produto.fotos[0] : '';
        popup.innerHTML =
            '<a href="/loja/produto.php?id=' + produto.id_produto + '" class="toast-conteudo">' +
                (foto ? '<img src="' + escaparHtml(foto) + '" class="toast-foto" alt="">' : '') +
                '<span class="toast-texto"><strong>' + escaparHtml(produto.nome) + '</strong><span>Voltou pra loja — já disponível!</span></span>' +
            '</a>' +
            '<button type="button" class="toast-fechar" aria-label="Fechar aviso">&times;</button>';

        document.body.appendChild(popup);
        popup.querySelector('.toast-fechar').addEventListener('click', function () {
            clearTimeout(popupTimeout);
            popup.remove();
        });

        clearTimeout(popupTimeout);
        popupTimeout = setTimeout(function () { popup.remove(); }, 5000);
    }

    function verificarNovidades() {
        const grade = document.getElementById('grade-produtos');
        const idsVisiveis = Array.from(grade.querySelectorAll('[data-id-produto]')).map(function (el) { return el.dataset.idProduto; });
        const params = new URLSearchParams({ desde: ultimaChecagem, categoria: categoriaAtual, ids: idsVisiveis.join(',') });

        fetch('/loja/ajax/verificar_novidades.php?' + params.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) { return; }
                ultimaChecagem = data.agora;

                // 1) Resincroniza TODO cartão visível com a disponibilidade real —
                // independe de ter mudado ou não desde a última rodada.
                (data.status || []).forEach(function (s) {
                    const card = grade.querySelector('[data-id-produto="' + s.id_produto + '"]');
                    if (card) { sincronizarCard(card, s.disponivel); }
                });

                // 2) Camada extra só pra celebrar quem voltou AGORA: borda dourada,
                // tag e um popup rápido com um dos itens.
                if (data.produtos.length > 0) {
                    data.produtos.forEach(function (produto) {
                        const existente = grade.querySelector('[data-id-produto="' + produto.id_produto + '"]');
                        if (existente) {
                            existente.classList.add('voltou');
                            if (!existente.querySelector('.tag-oportunidade')) {
                                const tag = document.createElement('span');
                                tag.className = 'tag-oportunidade';
                                tag.innerHTML = '<svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8L12 2Z"/></svg> Nova oportunidade';
                                existente.prepend(tag);
                            }
                        } else {
                            const novoCard = criarCardProduto(produto);
                            grade.prepend(novoCard);
                            iniciarCarrosseis(novoCard);
                        }
                    });

                    mostrarPopupOportunidade(data.produtos[0]);
                }

                // 3) Os avisos no topo da página (explicando "Em um carrinho" e
                // "voltou pra loja") têm que refletir a tela agora, não o que
                // era verdade quando a página carregou — senão ficam presos
                // avisando algo que já não é mais real.
                atualizarAvisosGerais(grade);
            })
            .catch(function () {});
    }

    function atualizarAvisosGerais(grade) {
        const avisoReservado = document.getElementById('aviso-reservado');
        if (avisoReservado) {
            avisoReservado.hidden = grade.querySelector('.product-card.reservado') === null;
        }
        const avisoVoltou = document.getElementById('aviso-voltou-geral');
        if (avisoVoltou) {
            avisoVoltou.hidden = grade.querySelector('.product-card.voltou') === null;
        }
    }

    setInterval(verificarNovidades, 5000);

    // Delegação de evento — funciona tanto pros cartões já renderizados pelo PHP
    // quanto pros injetados depois pelo poll de novidades.
    document.getElementById('grade-produtos').addEventListener('click', function (e) {
        const btnFav = e.target.closest('.favoritar');
        if (btnFav) {
            e.preventDefault();
            e.stopPropagation();
            fetch('/loja/ajax/favoritar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'id_produto=' + btnFav.dataset.idProduto
            }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data.success) {
                    if (data.message) { alert(data.message); }
                    if (!<?= json_encode(!empty($_SESSION['id_cliente'])) ?>) { window.location.href = '/loja/cadastro.php'; }
                    return;
                }
                btnFav.classList.toggle('ativo', data.favoritado);
                btnFav.setAttribute('aria-label', data.favoritado ? 'Remover dos favoritos' : 'Adicionar aos favoritos');
            }).catch(function () {});
            return;
        }

        const btnShare = e.target.closest('.compartilhar');
        if (btnShare) {
            e.preventDefault();
            e.stopPropagation();
            window.open(btnShare.dataset.whatsappLink, '_blank', 'noopener');
        }
    });
})();
</script>
</main>
<?php require __DIR__ . '/../includes/loja_footer.php'; ?>
</body>
</html>
