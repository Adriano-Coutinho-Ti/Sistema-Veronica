<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirClienteLogado(bool $modo_json = false): void
{
    if (empty($_SESSION['id_cliente'])) {
        if ($modo_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
        } else {
            header('Location: /loja/cadastro.php');
        }
        exit;
    }
}
