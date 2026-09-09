<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

$id_produto = (int) ($_POST['id_produto'] ?? 0);

$stmtP = $pdo->prepare('SELECT id_produto FROM produtos WHERE id_produto = :id');
$stmtP->execute([':id' => $id_produto]);
if (!$stmtP->fetch()) {
    header('Location: /produtos/lista.php?upload_status=error&msg=produto_nao_encontrado');
    exit;
}

if (empty($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error');
    exit;
}

$stmtCount = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(ordem), 0) FROM produto_fotos WHERE id_produto = :id');
$stmtCount->execute([':id' => $id_produto]);
[$totalFotos, $maiorOrdem] = $stmtCount->fetch(PDO::FETCH_NUM);

if ($totalFotos >= 5) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error&msg=limite_5_fotos');
    exit;
}

$targetWidth = 473;
$targetHeight = 400;
$targetDir = __DIR__ . '/../../assets/img/produtos/' . $id_produto . '/';
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

$novaOrdem = $maiorOrdem + 1;
$destino = $targetDir . $novaOrdem . '.png';

$info = getimagesize($_FILES['foto']['tmp_name']);
$mime = $info['mime'] ?? '';

$origem = match ($mime) {
    'image/jpeg' => imagecreatefromjpeg($_FILES['foto']['tmp_name']),
    'image/png' => imagecreatefrompng($_FILES['foto']['tmp_name']),
    'image/gif' => imagecreatefromgif($_FILES['foto']['tmp_name']),
    default => null,
};

if ($origem === null || $origem === false) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error&msg=formato_invalido');
    exit;
}

$novaImagem = imagecreatetruecolor($targetWidth, $targetHeight);
imagealphablending($novaImagem, false);
imagesavealpha($novaImagem, true);
imagecopyresampled($novaImagem, $origem, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($origem), imagesy($origem));
$sucesso = imagepng($novaImagem, $destino, 9);
imagedestroy($origem);
imagedestroy($novaImagem);

if (!$sucesso) {
    header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=error&msg=falha_processamento');
    exit;
}

$caminhoRelativo = 'assets/img/produtos/' . $id_produto . '/' . $novaOrdem . '.png';
$pdo->prepare('INSERT INTO produto_fotos (id_produto, ordem, caminho_arquivo) VALUES (:ip, :ordem, :caminho)')
    ->execute([':ip' => $id_produto, ':ordem' => $novaOrdem, ':caminho' => $caminhoRelativo]);

header('Location: /produtos/editar.php?id=' . $id_produto . '&upload_status=success');
exit;
