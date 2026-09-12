<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth_cliente.php';
exigirClienteLogado();

$id_cliente = (int) $_SESSION['id_cliente'];
$valor = (float) str_replace(',', '.', $_POST['valor_solicitado'] ?? '0');

if ($valor <= 0) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Informe um valor válido.'));
    exit;
}

$stmt = $pdo->prepare("SELECT id_solicitacao FROM solicitacoes_credito WHERE id_cliente = :ic AND status = 'Pendente'");
$stmt->execute([':ic' => $id_cliente]);
if ($stmt->fetch()) {
    header('Location: /loja/minha_divida.php?erro=' . urlencode('Você já tem uma solicitação em análise.'));
    exit;
}

$pdo->prepare('INSERT INTO solicitacoes_credito (id_cliente, valor_solicitado) VALUES (:ic, :valor)')
    ->execute([':ic' => $id_cliente, ':valor' => $valor]);

header('Location: /loja/minha_divida.php?solicitado=1');
exit;
