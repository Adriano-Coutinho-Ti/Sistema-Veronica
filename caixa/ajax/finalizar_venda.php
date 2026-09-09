<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$pagamentos = json_decode($_POST['pagamentos'] ?? '[]', true) ?: [];

// finalizarVenda() em si não checa id_usuario (também é chamada pelo polling/webhook
// do Pix, sem sessão de ninguém) — a checagem de posse tem que acontecer aqui, no
// wrapper AJAX que É chamado com sessão, senão um operador finaliza a venda de outro.
$stmtOwn = $pdo->prepare("SELECT id_venda FROM vendas WHERE id_venda = :id AND id_usuario = :iu AND status = 'Reservado'");
$stmtOwn->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
if (!$stmtOwn->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Venda não encontrada ou já finalizada.', 'redirect' => null]);
    exit;
}

echo json_encode(finalizarVenda($pdo, $id_venda, $pagamentos));
