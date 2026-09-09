<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirClienteLogado(): void
{
    if (empty($_SESSION['id_cliente'])) {
        header('Location: /loja/cadastro.php');
        exit;
    }
}
