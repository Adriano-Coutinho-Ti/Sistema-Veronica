<?php
/**
 * Calculadora de hash pra senha de recuperação (dev_usuarios.senha_recuperacao_hash).
 * Só calcula e devolve o hash -- não grava nada no banco. O dev copia o
 * resultado e cola direto no banco por fora (phpMyAdmin/SQL), do jeito
 * combinado: essa senha nunca é salva por nenhum formulário do sistema.
 */
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_dev.php';
exigirDev();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$senha = $_POST['senha'] ?? '';
if ($senha === '') {
    echo json_encode(['success' => false, 'message' => 'Digite uma senha.']);
    exit;
}

echo json_encode(['success' => true, 'hash' => password_hash($senha, PASSWORD_DEFAULT)]);
