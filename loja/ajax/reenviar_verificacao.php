<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
require_once __DIR__ . '/../../includes/loja.php';
exigirClienteLogado(true);
header('Content-Type: application/json');

$id_cliente = (int) $_SESSION['id_cliente'];

$stmt = $pdo->prepare('SELECT nome, email, email_verificado_em FROM clientes WHERE id_cliente = :id');
$stmt->execute([':id' => $id_cliente]);
$cliente = $stmt->fetch();

if (!$cliente || !$cliente['email']) {
    echo json_encode(['success' => false, 'message' => 'Nenhum e-mail cadastrado.']);
    exit;
}

if ($cliente['email_verificado_em'] !== null) {
    echo json_encode(['success' => false, 'message' => 'Seu e-mail já está verificado.']);
    exit;
}

$resultado = reenviarCodigoVerificacaoEmail($pdo, $id_cliente, $cliente['email'], $cliente['nome']);
echo json_encode([
    'success' => $resultado['success'],
    'message' => $resultado['success']
        ? 'Código reenviado! Confira sua caixa de entrada (e o spam).'
        : 'Não foi possível enviar o e-mail agora. Tente novamente em instantes.',
]);
