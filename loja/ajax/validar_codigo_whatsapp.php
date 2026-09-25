<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

$id_cliente = (int) $_SESSION['id_cliente'];
$codigo = trim($_POST['codigo'] ?? '');

// Compara a expiração dentro do próprio SQL (NOW() do MySQL), nunca com
// strtotime()/time() do PHP -- ver mesma nota em validar_codigo_email.php.
$stmt = $pdo->prepare(
    "SELECT token_verificacao_whatsapp, (token_verificacao_whatsapp_expira_em > NOW()) AS codigo_valido
     FROM clientes WHERE id_cliente = :id"
);
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();

if (!$cliente || empty($cliente['token_verificacao_whatsapp'])) {
    echo json_encode(['success' => false, 'message' => 'Nenhum código pendente. Peça um novo código.']);
    exit;
}

if (!$cliente['codigo_valido']) {
    echo json_encode(['success' => false, 'message' => 'Esse código expirou. Clique em "Não recebi" pra pedir outro.']);
    exit;
}

if (!hash_equals($cliente['token_verificacao_whatsapp'], $codigo)) {
    echo json_encode(['success' => false, 'message' => 'Código incorreto. Confira e tente de novo.']);
    exit;
}

$pdo->prepare('UPDATE clientes SET whatsapp_verificado_em = NOW(), login_whatsapp_bloqueado = 0, token_verificacao_whatsapp = NULL, token_verificacao_whatsapp_expira_em = NULL WHERE id_cliente = :id')
    ->execute([':id' => $id_cliente]);

echo json_encode(['success' => true, 'message' => 'WhatsApp confirmado!']);
