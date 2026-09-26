<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/superfrete.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$acao = $_POST['acao'] ?? '';

if (!superfreteEtiquetasAtivas($pdo)) {
    echo json_encode(['success' => false, 'message' => 'Etiquetas não estão ativas.']);
    exit;
}
if (!superfreteEnvioDaVenda($pdo, $id_venda)) {
    echo json_encode(['success' => false, 'message' => 'Pedido sem envio SuperFrete.']);
    exit;
}

if ($acao === 'salvar_nota') {
    $numero = preg_replace('/\D/', '', (string) ($_POST['nota_numero'] ?? ''));
    $chave = preg_replace('/\D/', '', (string) ($_POST['nota_chave'] ?? ''));
    if ($numero === '' || strlen($numero) > 20 || strlen($chave) !== 44) {
        echo json_encode(['success' => false, 'message' => 'Informe o número da nota e a chave de acesso com 44 dígitos.']);
        exit;
    }
    $pdo->prepare('UPDATE vendas_envio SET nota_numero = :n, nota_chave = :c WHERE id_venda = :iv AND superfrete_order_id IS NULL')
        ->execute([':n' => $numero, ':c' => $chave, ':iv' => $id_venda]);
    echo json_encode(['success' => true, 'message' => 'Nota fiscal salva.']);
    exit;
}

if ($acao === 'gerar') {
    echo json_encode(superfreteGerarEtiqueta($pdo, $id_venda));
    exit;
}

echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
