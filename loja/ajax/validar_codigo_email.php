<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

$id_cliente = (int) $_SESSION['id_cliente'];
$codigo = trim($_POST['codigo'] ?? '');

// Compara a expiração dentro do próprio SQL (NOW() do MySQL), nunca com
// strtotime()/time() do PHP -- os dois relógios podem estar em fusos
// diferentes (já aconteceu antes neste projeto), e comparar em PHP contra
// um horário que veio do MySQL dá resultado errado nesse caso.
$stmt = $pdo->prepare(
    "SELECT token_verificacao_email, (token_verificacao_expira_em > NOW()) AS codigo_valido
     FROM clientes WHERE id_cliente = :id"
);
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();

if (!$cliente || empty($cliente['token_verificacao_email'])) {
    echo json_encode(['success' => false, 'message' => 'Nenhum código pendente. Peça um novo código.']);
    exit;
}

if (!$cliente['codigo_valido']) {
    echo json_encode(['success' => false, 'message' => 'Esse código expirou. Clique em "Não recebi o e-mail" pra pedir outro.']);
    exit;
}

if (!hash_equals($cliente['token_verificacao_email'], $codigo)) {
    echo json_encode(['success' => false, 'message' => 'Código incorreto. Confira e tente de novo.']);
    exit;
}

$pdo->prepare('UPDATE clientes SET email_verificado_em = NOW(), token_verificacao_email = NULL, token_verificacao_expira_em = NULL WHERE id_cliente = :id')
    ->execute([':id' => $id_cliente]);

echo json_encode(['success' => true, 'message' => 'E-mail confirmado!']);
