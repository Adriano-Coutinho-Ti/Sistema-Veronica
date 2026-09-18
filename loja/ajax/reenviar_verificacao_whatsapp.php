<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

$id_cliente = (int) $_SESSION['id_cliente'];

$whatsappHabilitado = (bool) $pdo->query('SELECT whatsapp_verificacao_ativo FROM config_dev WHERE id_config = 1')->fetchColumn();
if (!$whatsappHabilitado) {
    echo json_encode(['success' => false, 'message' => 'Verificação por WhatsApp não está disponível.']);
    exit;
}

$stmt = $pdo->prepare('SELECT nome, whatsapp, whatsapp_verificado_em FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();

if (!$cliente || !$cliente['whatsapp']) {
    echo json_encode(['success' => false, 'message' => 'Nenhum WhatsApp cadastrado.']);
    exit;
}

if ($cliente['whatsapp_verificado_em'] !== null) {
    echo json_encode(['success' => false, 'message' => 'Seu WhatsApp já está verificado.']);
    exit;
}

$resultado = reenviarCodigoVerificacaoWhatsapp($pdo, $id_cliente, $cliente['whatsapp'], $cliente['nome']);
echo json_encode([
    'success' => $resultado['success'],
    'message' => $resultado['success']
        ? 'Código reenviado pro seu WhatsApp!'
        : $resultado['message'],
]);
