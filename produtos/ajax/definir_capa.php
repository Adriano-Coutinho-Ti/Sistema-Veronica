<?php
/**
 * Troca qual foto é a "capa" do produto (a de ordem=1 — a que aparece no
 * catálogo, no card e na prévia do WhatsApp). Faz a troca em 3 passos com
 * um valor de ordem temporário (99, fora do intervalo 1..MAX_FOTOS_PRODUTO)
 * pra nunca violar a UNIQUE KEY (id_produto, ordem) no meio do caminho —
 * um UPDATE direto de duas linhas pra valores um do outro sempre esbarra
 * nessa constraint na primeira das duas.
 */
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
exigirLogin();

$id_produto = (int) ($_POST['id_produto'] ?? 0);
$id_foto = (int) ($_POST['id_foto'] ?? 0);

$stmt = $pdo->prepare('SELECT id_foto, ordem FROM produto_fotos WHERE id_foto = :id AND id_produto = :ip');
$stmt->execute([':id' => $id_foto, ':ip' => $id_produto]);
$fotoEscolhida = $stmt->fetch();

if ($fotoEscolhida && (int) $fotoEscolhida['ordem'] !== 1) {
    $pdo->beginTransaction();
    try {
        $ordemOriginal = (int) $fotoEscolhida['ordem'];

        $pdo->prepare('UPDATE produto_fotos SET ordem = 99 WHERE id_foto = :id')
            ->execute([':id' => $id_foto]);
        $pdo->prepare('UPDATE produto_fotos SET ordem = :ordem WHERE id_produto = :ip AND ordem = 1')
            ->execute([':ordem' => $ordemOriginal, ':ip' => $id_produto]);
        $pdo->prepare('UPDATE produto_fotos SET ordem = 1 WHERE id_foto = :id')
            ->execute([':id' => $id_foto]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

header('Location: /produtos/editar.php?id=' . $id_produto);
exit;
