<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Cliente movido pra lixeira (clientes.excluido_em) perde o acesso na hora --
// derruba a sessão dele em toda página que carrega este arquivo, em vez de
// esperar ele deslogar sozinho.
if (!empty($_SESSION['id_cliente']) && isset($pdo) && $pdo instanceof PDO) {
    $stmtLixeira = $pdo->prepare('SELECT excluido_em FROM clientes WHERE id_cliente = :id');
    $stmtLixeira->execute([':id' => (int) $_SESSION['id_cliente']]);
    $linhaLixeira = $stmtLixeira->fetch();
    if (!$linhaLixeira || $linhaLixeira['excluido_em'] !== null) {
        unset($_SESSION['id_cliente'], $_SESSION['nome_cliente']);
    }
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
