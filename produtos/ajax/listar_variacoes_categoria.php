<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

header('Content-Type: application/json');

$id_categoria = (int) ($_GET['id_categoria'] ?? 0);
$stmt = $pdo->prepare(
    'SELECT v.id_variacao, v.nome FROM categoria_variacoes cv
     JOIN variacoes v ON v.id_variacao = cv.id_variacao
     WHERE cv.id_categoria = :ic ORDER BY v.nome'
);
$stmt->execute([':ic' => $id_categoria]);
echo json_encode(['variacoes' => $stmt->fetchAll()]);
