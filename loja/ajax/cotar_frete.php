<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/superfrete.php';
exigirClienteLogado();
header('Content-Type: application/json');

if (!superfreteAtivo($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Frete indisponível.']);
    exit;
}

$id_venda = buscarCarrinhoDoCliente($pdo, (int) $_SESSION['id_cliente']);
if (!$id_venda) {
    echo json_encode(['success' => false, 'message' => 'Carrinho não encontrado.']);
    exit;
}

try {
    $opcoes = superfreteCotar($pdo, $id_venda, $_POST['cep'] ?? '');
    if (empty($opcoes)) {
        echo json_encode(['success' => false, 'message' => 'Nenhum serviço de entrega disponível para esse CEP.']);
        exit;
    }
    echo json_encode(['success' => true, 'opcoes' => $opcoes]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
