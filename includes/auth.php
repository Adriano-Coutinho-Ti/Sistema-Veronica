<?php

require_once __DIR__ . '/travamento.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirLogin(): void
{
    if (empty($_SESSION['id_usuario'])) {
        header('Location: /login.php');
        exit;
    }
    // Checa mesmo quem já estava logado antes do travamento ser ativado --
    // não é só uma trava no momento do login.
    global $pdo;
    if ($pdo) {
        verificarTravamentoPagamento($pdo);
    }
}

function exigirAdmin(): void
{
    exigirLogin();
    if (($_SESSION['perfil'] ?? '') !== 'Admin') {
        http_response_code(403);
        echo 'Acesso restrito ao administrador.';
        exit;
    }
}
