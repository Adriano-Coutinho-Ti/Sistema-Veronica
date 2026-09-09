<?php
require_once __DIR__ . '/../includes/auth_cliente.php';
unset($_SESSION['id_cliente'], $_SESSION['nome_cliente']);
header('Location: /loja/index.php');
exit;
