<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/superfrete.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$acao = $_POST['acao'] ?? '';

if (!superfreteEtiquetasAtivas($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Etiquetas não estão ativas.']);
    exit;
}
if (!superfreteEnvioDaVenda($pdo, $id_venda)) {
    echo json_encode(['success' => false, 'message' => 'Pedido sem envio SuperFrete.']);
    exit;
}

if ($acao === 'gerar') {
    echo json_encode(superfreteGerarEtiqueta($pdo, $id_venda));
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
