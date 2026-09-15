<?php
require_once __DIR__ . '/../includes/auth_dev.php';
unset($_SESSION['dev_usuario_id'], $_SESSION['dev_usuario_nome']);
header('Location: /painel_dev/login.php');
exit;
