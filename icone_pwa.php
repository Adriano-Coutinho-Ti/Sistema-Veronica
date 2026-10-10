<?php
require_once __DIR__ . '/conecta_bd.php';
require_once __DIR__ . '/includes/config_dev.php';
require_once __DIR__ . '/includes/pwa.php';

$app = ($_GET['app'] ?? '') === 'admin' ? 'admin' : 'loja';
$tam = max(48, min(512, (int) ($_GET['tam'] ?? 192)));
[$tema] = pwaCores($pdo, $app);
[$fr, $fg, $fb] = sscanf($tema, '#%02x%02x%02x');

header('Cache-Control: public, max-age=86400');

if (!function_exists('imagecreatetruecolor')) {
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="' . htmlspecialchars($tema) . '"/><circle cx="50" cy="50" r="30" fill="#fff"/></svg>';
    exit;
}

$img = imagecreatetruecolor($tam, $tam);
imagefill($img, 0, 0, imagecolorallocate($img, $fr, $fg, $fb));

// Logo num quadrado branco no meio (a "zona segura" de ícone mascarável é ~62%).
$logo = pwaLogo($pdo);
$origem = $logo ? @imagecreatefromstring((string) file_get_contents($logo['caminho'])) : false;
$lado = (int) round($tam * 0.62);
$x = (int) (($tam - $lado) / 2);
imagefilledrectangle($img, $x, $x, $x + $lado, $x + $lado, imagecolorallocate($img, 255, 255, 255));

if ($origem !== false) {
    $ow = imagesx($origem);
    $oh = imagesy($origem);
    $esc = min(($lado * 0.88) / $ow, ($lado * 0.88) / $oh);
    $nw = (int) round($ow * $esc);
    $nh = (int) round($oh * $esc);
    imagealphablending($img, true);
    imagecopyresampled($img, $origem, (int) ($x + ($lado - $nw) / 2), (int) ($x + ($lado - $nh) / 2), 0, 0, $nw, $nh, $ow, $oh);
} else {
    // Sem logo: inicial do nome da loja, na cor do tema.
    $nome = (string) $pdo->query('SELECT nome_loja FROM config_loja WHERE id_config = 1')->fetchColumn();
    $inicial = strtoupper(mb_substr(trim($nome) ?: 'L', 0, 1));
    $cor = imagecolorallocate($img, $fr, $fg, $fb);
    $fonte = null;
    foreach (['C:/Windows/Fonts/arialbd.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf', '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf'] as $f) {
        if (is_file($f)) {
            $fonte = $f;
            break;
        }
    }
    if ($fonte && function_exists('imagettftext')) {
        $t = $tam * 0.36;
        $b = imagettfbbox($t, 0, $fonte, $inicial);
        imagettftext($img, $t, 0, (int) (($tam - ($b[2] - $b[0])) / 2 - $b[0]), (int) (($tam + ($b[1] - $b[7])) / 2 - $b[1]), $cor, $fonte, $inicial);
    } else {
        $mini = imagecreatetruecolor(8, 16);
        imagefill($mini, 0, 0, imagecolorallocate($mini, 255, 255, 255));
        imagestring($mini, 5, 0, 0, $inicial, imagecolorallocate($mini, $fr, $fg, $fb));
        imagecopyresized($img, $mini, (int) ($tam * 0.38), (int) ($tam * 0.30), 0, 0, (int) ($tam * 0.24), (int) ($tam * 0.40), 8, 16);
    }
}

header('Content-Type: image/png');
imagepng($img);
