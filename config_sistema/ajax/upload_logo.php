<?php
/**
 * Recebe a logo já recortada pelo Cropper.js no navegador (config_sistema/aparencia.php) e:
 * 1. Reprocessa via GD — decodifica de novo (nunca confia só no que o navegador mandou),
 *    mantém a transparência e reduz se passar de 600px no maior lado.
 * 2. Salva como PNG em assets/img/logo/ com nome novo a cada envio (assim o navegador e o
 *    ícone do app nunca ficam presos na logo antiga) e apaga as logos anteriores.
 * 3. Grava o caminho em config_loja.logo_arquivo.
 * Responde em JSON.
 */
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirAdmin();

header('Content-Type: application/json');

function responderLogo(bool $success, string $message = '', array $extra = []): never
{
    echo json_encode(['success' => $success, 'message' => $message] + $extra);
    exit;
}

if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
    $codigo = $_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE;
    responderLogo(false, in_array($codigo, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'A imagem é grande demais para o servidor. Use uma menor.'
        : 'Nenhuma imagem recebida.');
}

$tmp = $_FILES['logo']['tmp_name'];
$info = @getimagesize($tmp);
$origem = match ($info['mime'] ?? '') {
    'image/jpeg' => @imagecreatefromjpeg($tmp),
    'image/png' => @imagecreatefrompng($tmp),
    'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
    default => false,
};
if ($origem === false || $origem === null) {
    responderLogo(false, 'Formato de imagem inválido (use JPEG ou PNG).');
}

// Reduz só se for maior que 600px no maior lado; mantém a transparência.
$largura = imagesx($origem);
$altura = imagesy($origem);
$escala = min(1, 600 / max($largura, $altura));
$novaLargura = max(1, (int) round($largura * $escala));
$novaAltura = max(1, (int) round($altura * $escala));
$nova = imagecreatetruecolor($novaLargura, $novaAltura);
imagealphablending($nova, false);
imagesavealpha($nova, true);
imagefill($nova, 0, 0, imagecolorallocatealpha($nova, 0, 0, 0, 127));
imagecopyresampled($nova, $origem, 0, 0, 0, 0, $novaLargura, $novaAltura, $largura, $altura);
imagedestroy($origem);

$dir = __DIR__ . '/../../assets/img/logo/';
if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
    error_log('upload_logo: não consegui criar ' . $dir);
    responderLogo(false, 'O servidor não conseguiu criar a pasta da logo. Avise o desenvolvedor (permissão de escrita em assets/img/logo).');
}
if (!is_writable($dir)) {
    error_log('upload_logo: sem permissão de escrita em ' . $dir);
    responderLogo(false, 'O servidor não tem permissão para gravar a logo. Avise o desenvolvedor (permissão de escrita em assets/img/logo).');
}

$nomeArquivo = 'logo-' . bin2hex(random_bytes(4)) . '.png';
if (!@imagepng($nova, $dir . $nomeArquivo, 9)) {
    imagedestroy($nova);
    error_log('upload_logo: imagepng falhou em ' . $dir . $nomeArquivo);
    responderLogo(false, 'Falha ao gravar a logo no servidor.');
}
imagedestroy($nova);

// Só depois de gravar a nova: apaga as logos antigas (inclusive a "logo.png" do formato anterior).
foreach (glob($dir . 'logo*.png') ?: [] as $antigo) {
    if (basename($antigo) !== $nomeArquivo) {
        @unlink($antigo);
    }
}

$caminho = 'assets/img/logo/' . $nomeArquivo;
$pdo->prepare('UPDATE config_loja SET logo_arquivo = :l WHERE id_config = 1')->execute([':l' => $caminho]);

responderLogo(true, 'Logo atualizada.', ['url' => '/' . $caminho]);
