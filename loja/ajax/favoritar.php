<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

$id_produto = (int) ($_POST['id_produto'] ?? 0);
$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare('SELECT id_favorito FROM favoritos WHERE id_cliente = :ic AND id_produto = :ip');
$stmt->execute([':ic' => $id_cliente, ':ip' => $id_produto]);
$existente = $stmt->fetch();

if ($existente) {
    $pdo->prepare('DELETE FROM favoritos WHERE id_favorito = :id')->execute([':id' => $existente['id_favorito']]);
    echo json_encode(['success' => true, 'favoritado' => false]);
    exit;
}

try {
    $pdo->prepare('INSERT INTO favoritos (id_cliente, id_produto) VALUES (:ic, :ip)')
        ->execute([':ic' => $id_cliente, ':ip' => $id_produto]);
    echo json_encode(['success' => true, 'favoritado' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Produto não encontrado.']);
}
