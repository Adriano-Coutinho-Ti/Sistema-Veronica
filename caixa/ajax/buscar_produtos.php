<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();
header('Content-Type: application/json');

$termo = trim($_GET['termo'] ?? '');
if ($termo === '') {
    echo json_encode(['produtos' => []]);
    exit;
}

// Busca por nome OU pelo código de 3 dígitos da etiqueta física — o
// operador pode digitar "384" e cair direto no produto, sem precisar
// saber o nome.
$stmt = $pdo->prepare(
    'SELECT pv.id_produto_variacao, p.nome AS nome_produto, p.codigo,
            GROUP_CONCAT(vv.valor SEPARATOR " / ") AS descricao_combinacao,
            COALESCE(pv.preco, p.preco_base) AS preco, pv.estoque
     FROM produto_variacoes pv
     JOIN produtos p ON p.id_produto = pv.id_produto
     LEFT JOIN produto_variacao_valores pvv ON pvv.id_produto_variacao = pv.id_produto_variacao
     LEFT JOIN variacao_valores vv ON vv.id_valor = pvv.id_valor
     WHERE p.ativo = 1 AND pv.estoque > 0 AND (p.nome LIKE :termo OR p.codigo = :termoExato)
     GROUP BY pv.id_produto_variacao, p.nome, p.codigo, pv.preco, p.preco_base, pv.estoque
     ORDER BY p.nome
     LIMIT 20'
);
$stmt->execute([':termo' => '%' . $termo . '%', ':termoExato' => $termo]);
$produtos = $stmt->fetchAll();

foreach ($produtos as &$p) {
    $p['nome_completo'] = ($p['codigo'] ? '[' . $p['codigo'] . '] ' : '') . $p['nome_produto'] . ($p['descricao_combinacao'] ? ' (' . $p['descricao_combinacao'] . ')' : '');
    $p['preco'] = (float) $p['preco'];
    $p['estoque'] = (int) $p['estoque'];
}
unset($p);

echo json_encode(['produtos' => $produtos]);
