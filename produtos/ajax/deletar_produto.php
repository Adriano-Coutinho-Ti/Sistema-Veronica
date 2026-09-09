<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

$id_produto = (int) ($_POST['id_produto'] ?? 0);

$stmt = $pdo->prepare('SELECT caminho_arquivo FROM produto_fotos WHERE id_produto = :ip');
$stmt->execute([':ip' => $id_produto]);
$fotos = $stmt->fetchAll();

foreach ($fotos as $foto) {
    $caminhoAbsoluto = __DIR__ . '/../../' . $foto['caminho_arquivo'];
    if (is_file($caminhoAbsoluto)) {
        unlink($caminhoAbsoluto);
    }
}

$pastaProduto = __DIR__ . '/../../assets/img/produtos/' . $id_produto;
if (is_dir($pastaProduto)) {
    rmdir($pastaProduto);
}

// ON DELETE CASCADE em produto_variacoes, produto_variacao_valores e produto_fotos
// cuida do resto — não há necessidade de apagar essas linhas manualmente aqui.
$pdo->prepare('DELETE FROM produtos WHERE id_produto = :id')->execute([':id' => $id_produto]);

header('Location: /produtos/lista.php?excluido=1');
exit;
