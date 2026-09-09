<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
exigirLogin();
header('Content-Type: application/json');

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$pagamentos = json_decode($_POST['pagamentos'] ?? '[]', true);

$formasValidas = ['Dinheiro', 'Débito', 'Crédito', 'Pix'];
$pagamentosValidos = is_array($pagamentos) && !empty($pagamentos) && array_is_list($pagamentos);
if ($pagamentosValidos) {
    foreach ($pagamentos as $pag) {
        if (
            !is_array($pag)
            || !isset($pag['forma'], $pag['valor'])
            || !in_array($pag['forma'], $formasValidas, true)
            || !is_numeric($pag['valor'])
            || (float) $pag['valor'] <= 0
        ) {
            $pagamentosValidos = false;
            break;
        }
    }
}

if (!$pagamentosValidos) {
    echo json_encode(['success' => false, 'message' => 'Dados de pagamento inválidos.', 'redirect' => null]);
    exit;
}

// finalizarVenda() em si não checa id_usuario (também é chamada pelo polling/webhook
// do Pix, sem sessão de ninguém) — a checagem de posse tem que acontecer aqui, no
// wrapper AJAX que É chamado com sessão, senão um operador finaliza a venda de outro.
// Também exige que o caixa da venda ainda esteja aberto — senão dá pra finalizar (com
// dinheiro inclusive) numa sessão que já foi fechada e reconciliada.
$stmtOwn = $pdo->prepare(
    "SELECT v.id_venda FROM vendas v
     JOIN caixa_sessoes cs ON cs.id_caixa = v.id_caixa
     WHERE v.id_venda = :id AND v.id_usuario = :iu AND v.status = 'Reservado' AND cs.status = 'aberto'"
);
$stmtOwn->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
if (!$stmtOwn->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Venda não encontrada ou já finalizada.', 'redirect' => null]);
    exit;
}

echo json_encode(finalizarVenda($pdo, $id_venda, $pagamentos));
