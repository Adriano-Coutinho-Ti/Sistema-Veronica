<?php

/**
 * Nome do sistema exibido no lugar de "Sistema Veronica" (cabeçalho do admin
 * e tela de login) — configurável pelo painel_dev. Cai no nome padrão se a
 * linha ainda não tiver sido preenchida.
 */
function nomeDoSistema(PDO $pdo): string
{
    $nome = $pdo->query('SELECT nome_sistema FROM config_dev WHERE id_config = 1')->fetchColumn();
    return ($nome !== false && $nome !== null && $nome !== '') ? $nome : 'Sistema Veronica';
}

/**
 * Linha inteira de config_dev — usada pelo próprio painel_dev pra preencher
 * o formulário com os valores salvos.
 */
function buscarConfigDev(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM config_dev WHERE id_config = 1')->fetch() ?: [];
}
