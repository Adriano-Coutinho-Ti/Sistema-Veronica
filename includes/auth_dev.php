<?php
/**
 * Sessão do painel_dev é completamente separada da sessão de login da loja
 * ($_SESSION['id_usuario']) — usa suas próprias chaves de sessão, então dá
 * pra estar logado como Admin da loja e como DevMaster ao mesmo tempo no
 * mesmo navegador sem um "atropelar" o outro.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function exigirDev(): void
{
    if (empty($_SESSION['dev_usuario_id'])) {
        header('Location: /painel_dev/login.php');
        exit;
    }
}
