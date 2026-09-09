<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$id_cliente = (int) ($_POST['id_cliente'] ?? 0);

$pdo->prepare("UPDATE vendas SET id_cliente = :ic WHERE id_venda = :iv AND id_usuario = :iu AND status = 'Reservado'")
    ->execute([':ic' => $id_cliente, ':iv' => $id_venda, ':iu' => $_SESSION['id_usuario']]);

echo json_encode(['success' => true]);
