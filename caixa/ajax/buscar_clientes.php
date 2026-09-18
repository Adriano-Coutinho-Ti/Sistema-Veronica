<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();
header('Content-Type: application/json');

$termo = trim($_GET['termo'] ?? '');
if ($termo === '') {
    echo json_encode(['clientes' => []]);
    exit;
}

$stmt = $pdo->prepare('SELECT id_cliente, nome, whatsapp FROM clientes WHERE excluido_em IS NULL AND (nome LIKE :termo OR whatsapp LIKE :termo) ORDER BY nome LIMIT 10');
$stmt->execute([':termo' => '%' . $termo . '%']);
echo json_encode(['clientes' => $stmt->fetchAll()]);
