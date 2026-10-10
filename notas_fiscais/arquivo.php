<?php
/**
 * Entrega o DANFE/cupom (PDF ou HTML) ou o XML de uma nota autorizada. Uso interno (exige login);
 * o cliente da loja usa loja/nota.php com o token do link.
 */
require_once __DIR__ . '/../conecta_bd.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fiscal.php';
exigirLogin();

$nota = fiscalNota($pdo, (int) ($_GET['id'] ?? 0));
if (!$nota || !in_array($nota['status'], ['autorizada', 'cancelada'], true)) {
    http_response_code(404);
    echo 'Nota não encontrada.';
    exit;
}

$xml = ($_GET['tipo'] ?? '') === 'xml';
$base64 = $xml ? $nota['xml_base64'] : $nota['arquivo_base64'];
$bin = $base64 ? base64_decode((string) $base64, true) : false;
if ($bin === false || $bin === '') {
    http_response_code(404);
    echo 'O arquivo desta nota ainda não está disponível.';
    exit;
}

$nome = ($nota['tipo'] === 'nfe' ? 'NFe' : 'NFCe') . '-' . ($nota['numero'] ?: $nota['id_nota']);
if ($xml) {
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '.xml"');
} elseif ($nota['arquivo_tipo'] === 'pdf') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $nome . '.pdf"');
} else {
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'");
}
header('X-Content-Type-Options: nosniff');
echo $bin;
