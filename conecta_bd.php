<?php
require_once __DIR__ . '/../../brechodaveve_config_credenciais.php';

// Notices/warnings/deprecations nunca devem ser impressos direto na resposta (isso já
// corrompeu JSON de endpoint AJAX duas vezes neste projeto) — loga mas nunca exibe.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
$options[class_exists('Pdo\\Mysql') ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";

$pdo = new PDO($dsn, $username, $password, $options);

// Raiz do projeto — todo caminho relativo salvo no banco (fotos de produto,
// logo da loja) é resolvido a partir daqui, não de __DIR__ do arquivo que
// está chamando (que muda de página pra página).
define('CAMINHO_RAIZ', __DIR__);

// Limite de fotos por produto — só regra da aplicação (produtos/editar.php,
// produtos/ajax/upload_foto.php), a coluna produto_fotos.ordem aceita até
// 255. Pra mudar o limite, só troca esse número.
define('MAX_FOTOS_PRODUTO', 6);

/**
 * Acrescenta "?v=<data de modificação do arquivo>" num caminho de foto —
 * mesmo truque já usado pro CSS/JS (includes/loja_header.php,
 * includes/admin_header.php). Sem isso, trocar a foto de um produto
 * (o arquivo final continua com o mesmo nome) não atualizava pra quem já
 * tinha a página aberta: o navegador seguia servindo a versão antiga do
 * cache porque a URL nunca mudava.
 */
function fotoComVersao(?string $caminhoRelativo): ?string
{
    if (!$caminhoRelativo) {
        return $caminhoRelativo;
    }
    $versao = @filemtime(CAMINHO_RAIZ . '/' . $caminhoRelativo) ?: time();
    return $caminhoRelativo . '?v=' . $versao;
}
