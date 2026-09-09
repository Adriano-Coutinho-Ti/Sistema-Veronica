<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirLogin();
header('Content-Type: application/json');

$caixa = exigirCaixaAberto($pdo, true);

$id_venda = buscarVendaReservadaDoOperador($pdo, (int) $caixa['id_caixa'], (int) $_SESSION['id_usuario']);
if (!$id_venda) {
    $pdo->prepare('INSERT INTO vendas (id_caixa, id_usuario, status) VALUES (:ic, :iu, "Reservado")')
        ->execute([':ic' => $caixa['id_caixa'], ':iu' => $_SESSION['id_usuario']]);
    $id_venda = (int) $pdo->lastInsertId();
}

echo json_encode(['success' => true, 'id_venda' => $id_venda]);
