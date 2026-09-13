<?php
/**
 * Recebe uma foto já cortada em quadrado (500x500) pelo Cropper.js no
 * navegador (produtos/editar.php) e:
 * 1. Reprocessa via GD — nunca confia só no que o cliente mandou, decodifica
 *    de novo e corta um quadrado central antes de redimensionar, garantindo
 *    500x500 de verdade mesmo se o corte do navegador vier torto.
 * 2. Salva como JPEG (bem mais leve que PNG pra foto de verdade) em
 *    assets/img/produtos/<id_produto>/<ordem>.jpg.
 * 3. Registra em produto_fotos.
 * Responde em JSON — quem chama é fetch()/FormData, não um <form> comum.
 */
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

header('Content-Type: application/json');

function responderUpload(bool $success, string $message = ''): never
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

$id_produto = (int) ($_POST['id_produto'] ?? 0);

$stmtP = $pdo->prepare('SELECT id_produto FROM produtos WHERE id_produto = :id');
$stmtP->execute([':id' => $id_produto]);
if (!$stmtP->fetch()) {
    responderUpload(false, 'Produto não encontrado.');
}

if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    responderUpload(false, 'Nenhuma foto recebida.');
}

$stmtCount = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(ordem), 0) FROM produto_fotos WHERE id_produto = :id');
$stmtCount->execute([':id' => $id_produto]);
[$totalFotos, $maiorOrdem] = $stmtCount->fetch(PDO::FETCH_NUM);

if ($totalFotos >= 5) {
    responderUpload(false, 'Esse produto já tem o máximo de 5 fotos.');
}

$info = getimagesize($_FILES['foto']['tmp_name']);
$mime = $info['mime'] ?? '';

$origem = match ($mime) {
    'image/jpeg' => imagecreatefromjpeg($_FILES['foto']['tmp_name']),
    'image/png' => imagecreatefrompng($_FILES['foto']['tmp_name']),
    'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($_FILES['foto']['tmp_name']) : null,
    'image/gif' => imagecreatefromgif($_FILES['foto']['tmp_name']),
    default => null,
};

if ($origem === null || $origem === false) {
    responderUpload(false, 'Formato de imagem inválido.');
}

// Corta um quadrado central antes de redimensionar — o Cropper.js já manda
// quadrado, mas isso é rede de segurança caso venha algo fora do padrão (o
// servidor nunca confia cegamente no que o navegador processou).
$targetSize = 500;
$larguraOrigem = imagesx($origem);
$alturaOrigem = imagesy($origem);
$ladoCorte = min($larguraOrigem, $alturaOrigem);
$origemX = (int) (($larguraOrigem - $ladoCorte) / 2);
$origemY = (int) (($alturaOrigem - $ladoCorte) / 2);

$novaImagem = imagecreatetruecolor($targetSize, $targetSize);
imagefill($novaImagem, 0, 0, imagecolorallocate($novaImagem, 255, 255, 255));
imagecopyresampled($novaImagem, $origem, 0, 0, $origemX, $origemY, $targetSize, $targetSize, $ladoCorte, $ladoCorte);
imagedestroy($origem);

$targetDir = __DIR__ . '/../../assets/img/produtos/' . $id_produto . '/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0755, true);
}

$novaOrdem = $maiorOrdem + 1;
$destino = $targetDir . $novaOrdem . '.jpg';

// JPEG qualidade 88 — bem mais leve que PNG pra foto (não é ilustração com
// áreas de cor sólida/transparência, é foto de verdade), sem perda visível.
$sucesso = imagejpeg($novaImagem, $destino, 88);
imagedestroy($novaImagem);

if (!$sucesso) {
    responderUpload(false, 'Falha ao processar a imagem.');
}

$caminhoRelativo = 'assets/img/produtos/' . $id_produto . '/' . $novaOrdem . '.jpg';
$pdo->prepare('INSERT INTO produto_fotos (id_produto, ordem, caminho_arquivo) VALUES (:ip, :ordem, :caminho)')
    ->execute([':ip' => $id_produto, ':ordem' => $novaOrdem, ':caminho' => $caminhoRelativo]);

responderUpload(true);
