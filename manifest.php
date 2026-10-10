<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/config_dev.php';
require_once __DIR__ . '/includes/pwa.php';

$app = ($_GET['app'] ?? '') === 'admin' ? 'admin' : 'loja';
$nomeLoja = (string) $pdo->query('SELECT nome_loja FROM config_loja WHERE id_config = 1')->fetchColumn() ?: 'Loja';
[$tema, $fundo] = pwaCores($pdo, $app);
$versao = (pwaLogo($pdo)['mtime'] ?? 0) . '-' . (int) @filemtime(__FILE__);
$icone = fn(int $tam) => '/icone_pwa.php?app=' . $app . '&tam=' . $tam . '&v=' . $versao;

$nome = $app === 'admin' ? nomeDoSistema($pdo) : $nomeLoja;

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo json_encode([
    'name' => $nome,
    'short_name' => mb_substr($nome, 0, 12),
    'description' => $app === 'admin' ? 'Gestão da loja: pedidos, produtos, clientes e caixa.' : 'Compre online na ' . $nomeLoja . '.',
    'id' => $app === 'admin' ? '/dashboard.php' : '/loja/index.php',
    'start_url' => $app === 'admin' ? '/dashboard.php' : '/loja/index.php',
    'scope' => $app === 'admin' ? '/' : '/loja/',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'lang' => 'pt-BR',
    'background_color' => $fundo,
    'theme_color' => $tema,
    'icons' => [
        ['src' => $icone(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => $icone(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
