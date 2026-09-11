<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/pedidos.php';
exigirAdmin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);

echo json_encode(cancelarPedidoPago($pdo, $id_venda, (int) $_SESSION['id_usuario']));
