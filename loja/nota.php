<?php
/**
 * Nota fiscal do pedido para o cliente — sem login, protegida só pelo token do link (o mesmo
 * modelo do comprovante do PDV). Entrega o DANFE em PDF (ou HTML) da nota autorizada.
 */
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/fiscal.php';

$nota = fiscalNotaPublica($pdo, (string) ($_GET['t'] ?? ''));
$bin = $nota && $nota['arquivo_base64'] ? base64_decode((string) $nota['arquivo_base64'], true) : false;
if ($bin === false || $bin === '') {
    http_response_code(404);
    echo 'Nota fiscal não encontrada.';
    exit;
}

$nome = ($nota['tipo'] === 'nfe' ? 'NFe' : 'NFCe') . '-' . ($nota['numero'] ?: $nota['id_nota']);
if ($nota['arquivo_tipo'] === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nome . '.pdf"');
} else {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'");
}
header('X-Content-Type-Options: nosniff');
echo $bin;
