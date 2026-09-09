<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

header('Content-Type: application/json');

$id_categoria = (int) ($_GET['id_categoria'] ?? 0);
$ids_variacao = array_filter(array_map('intval', explode(',', $_GET['variacoes'] ?? '')));

if (empty($ids_variacao)) {
    echo json_encode(['combinacoes' => [[]]]);
    exit;
}

$grupos = [];
foreach ($ids_variacao as $id_variacao) {
    $stmt = $pdo->prepare(
        'SELECT vv.id_valor, vv.valor, v.nome AS nome_variacao
         FROM variacao_valores vv
         JOIN variacoes v ON v.id_variacao = vv.id_variacao
         JOIN categoria_variacoes cv ON cv.id_variacao = vv.id_variacao AND cv.id_categoria = :ic
         WHERE vv.id_variacao = :iv
         ORDER BY vv.valor'
    );
    $stmt->execute([':ic' => $id_categoria, ':iv' => $id_variacao]);
    $valores = $stmt->fetchAll();
    if ($valores) {
        $grupos[] = $valores;
    }
}

$combinacoes = [[]];
foreach ($grupos as $grupo) {
    $novasCombinacoes = [];
    foreach ($combinacoes as $combinacaoAtual) {
        foreach ($grupo as $valor) {
            $novasCombinacoes[] = array_merge($combinacaoAtual, [$valor]);
        }
    }
    $combinacoes = $novasCombinacoes;
}

echo json_encode(['combinacoes' => $combinacoes]);
