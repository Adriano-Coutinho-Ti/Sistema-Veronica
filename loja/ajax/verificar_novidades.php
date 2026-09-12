<?php
/**
 * Poll público (sem login) chamado pelo catálogo pra descobrir, sem recarregar
 * a página, quais produtos voltaram a ficar disponíveis desde a última
 * checagem — inclusive itens liberados pelo carrinho de OUTRA pessoa que
 * expirou. Retorna também o instante atual do próprio MySQL (não do PHP)
 * como próximo "desde", pelo mesmo motivo do cronômetro do carrinho: evita
 * depender do relógio do servidor PHP/navegador, que pode divergir do banco.
 */
require_once __DIR__ . '/../../conecta_bd.php';

header('Content-Type: application/json');

$desde = $_GET['desde'] ?? '';
$id_categoria = (int) ($_GET['categoria'] ?? 0);

if ($desde === '' || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) {
    echo json_encode(['success' => false, 'message' => 'Parâmetro "desde" inválido.']);
    exit;
}

$sql = "SELECT DISTINCT p.id_produto, p.nome, p.preco_base
        FROM produtos p
        JOIN produto_variacoes pv ON pv.id_produto = p.id_produto
        WHERE p.ativo = 1
          AND (pv.estoque - pv.estoque_reservado) > 0
          AND pv.liberado_em IS NOT NULL
          AND pv.liberado_em > :desde";
$params = [':desde' => $desde];
if ($id_categoria > 0) {
    $sql .= ' AND p.id_categoria = :ic';
    $params[':ic'] = $id_categoria;
}
$sql .= ' ORDER BY p.nome';

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
        $fotosPorProduto[(int) $f['id_produto']][] = $f['caminho_arquivo'];
    }
}

$resultado = array_map(function ($p) use ($fotosPorProduto) {
    return [
        'id_produto' => (int) $p['id_produto'],
        'nome' => $p['nome'],
        'preco_base' => (float) $p['preco_base'],
        'fotos' => $fotosPorProduto[(int) $p['id_produto']] ?? [],
    ];
}, $produtos);

$agora = $pdo->query('SELECT NOW()')->fetchColumn();

echo json_encode(['success' => true, 'agora' => $agora, 'produtos' => $resultado]);
