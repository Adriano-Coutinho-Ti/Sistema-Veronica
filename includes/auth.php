<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirLogin(): void
{
    if (empty($_SESSION['id_usuario'])) {
        header('Location: /login.php');
        exit;
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
