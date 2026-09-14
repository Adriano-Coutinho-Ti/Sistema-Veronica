<?php
/**
 * Poll público (sem login) chamado pelo catálogo — faz dois trabalhos diferentes
 * a cada checagem:
 *
 * 1) "novidades": produtos que voltaram a ficar disponíveis desde a última
 *    checagem (inclusive liberados pelo carrinho de OUTRA pessoa que expirou) —
 *    alimenta o destaque dourado / tag "Nova oportunidade" / popup.
 *
 * 2) "status": o estado ATUAL (sem depender de delta nenhum) de cada produto que
 *    já está desenhado na tela (passado em `ids`) — o cliente resincroniza esses
 *    cartões toda vez, mesmo os que não mudaram. Isso existe pra nunca deixar um
 *    cartão preso mostrando "em um carrinho" depois de já ter sido liberado (ou
 *    vice-versa, mostrando disponível quando outra pessoa acabou de reservar) —
 *    um poll baseado só em delta pode perder eventos; reafirmar o estado
 *    completo a cada rodada não perde.
 *
 * Retorna também o instante atual do próprio MySQL (não do PHP) como próximo
 * "desde", pelo mesmo motivo do cronômetro do carrinho: evita depender do
 * relógio do servidor PHP/navegador, que pode divergir do banco.
 */
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/loja.php';

header('Content-Type: application/json');

// Sem isso, o poll só REPORTA o que já está no banco — quem devolve a reserva de
// verdade (estoque_reservado, liberado_em, status da venda) é sempre
// liberarReservasExpiradas(), hoje só chamada quando alguém carrega uma página
// da loja. Numa madrugada sem ninguém navegando, o carrinho vencido nunca seria
// varrido e o poll ficaria reportando "reservado" pra sempre, mesmo com o prazo
// estourado. Chamar aqui faz o próprio poll da pessoa parada no catálogo ser
// quem dispara a liberação, sem depender de outra página carregar antes.
liberarReservasExpiradas($pdo);

$desde = $_GET['desde'] ?? '';
$id_categoria = (int) ($_GET['categoria'] ?? 0);
$idsVisiveis = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));

if ($desde === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetro "desde" inválido.']);
    exit;
}

$sql = "SELECT p.id_produto, p.nome, p.preco_base, SUM(pv.estoque - pv.estoque_reservado) AS disponivel
        FROM produtos p
        JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE p.ativo = 1
          AND p.id_produto IN (
              SELECT DISTINCT pv2.id_produto FROM produto_variacoes pv2
              WHERE pv2.liberado_em IS NOT NULL AND pv2.liberado_em > :desde
          )";
$params = [':desde' => $desde];
if ($id_categoria > 0) {
    $sql .= ' AND p.id_categoria = :ic';
    $params[':ic'] = $id_categoria;
}
$sql .= ' GROUP BY p.id_produto, p.nome, p.preco_base HAVING disponivel > 0 ORDER BY p.nome';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$produtos = $stmt->fetchAll();

$fotosPorProduto = [];
if (!empty($produtos)) {
    $ids = array_column($produtos, 'id_produto');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtFotos = $pdo->prepare(
        "SELECT id_produto, caminho_arquivo FROM produto_fotos WHERE id_produto IN ($placeholders) ORDER BY id_produto, ordem"
    );
    $stmtFotos->execute($ids);
    foreach ($stmtFotos->fetchAll() as $f) {
        $fotosPorProduto[(int) $f['id_produto']][] = fotoComVersao($f['caminho_arquivo']);
    }
}

$novidades = array_map(function ($p) use ($fotosPorProduto) {
    return [
        'id_produto' => (int) $p['id_produto'],
        'nome' => $p['nome'],
        'preco_base' => (float) $p['preco_base'],
        'disponivel' => (int) $p['disponivel'],
        'fotos' => $fotosPorProduto[(int) $p['id_produto']] ?? [],
    ];
}, $produtos);

$status = [];
if (!empty($idsVisiveis)) {
    $placeholdersStatus = implode(',', array_fill(0, count($idsVisiveis), '?'));
    $stmtStatus = $pdo->prepare(
        "SELECT pv.id_produto, MAX(p.estoque_gerenciado) AS estoque_gerenciado, SUM(pv.estoque - pv.estoque_reservado) AS disponivel
         FROM produto_variacoes pv
         JOIN produtos p ON p.id_produto = pv.id_produto
         WHERE pv.id_produto IN ($placeholdersStatus)
         GROUP BY pv.id_produto"
    );
    $stmtStatus->execute($idsVisiveis);
    foreach ($stmtStatus->fetchAll() as $s) {
        $status[] = [
            'id_produto' => (int) $s['id_produto'],
            'disponivel' => (int) $s['disponivel'],
            'estoque_gerenciado' => (int) $s['estoque_gerenciado'],
        ];
    }
}

$agora = $pdo->query('SELECT NOW()')->fetchColumn();

echo json_encode(['success' => true, 'agora' => $agora, 'produtos' => $novidades, 'status' => $status]);
