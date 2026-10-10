<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/whatsapp_conexao.php';
exigirAdmin();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Requisição inválida.']);
    exit;
}

$acao = $_POST['acao'] ?? '';

try {
    if (!whatsappModuloDevAtivo($pdo)) {
        echo json_encode(['success' => false, 'message' => 'O WhatsApp não está habilitado para esta loja.']);
        exit;
    }

    if ($acao === 'aceitar') {
        whatsappConexaoAceitar($pdo, (int) $_SESSION['id_usuario']);
        echo json_encode(['success' => true]);
    } elseif ($acao === 'conectar') {
        $r = whatsappConexaoConectar($pdo);
        echo json_encode(['success' => true] + $r);
    } elseif ($acao === 'estado') {
        $r = whatsappConexaoEstado($pdo);
        echo json_encode(['success' => true] + $r);
    } elseif ($acao === 'desconectar') {
        whatsappConexaoDesconectar($pdo);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ação inválida.']);
    }
} catch (InvalidArgumentException | RuntimeException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('config_sistema/ajax/whatsapp: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Não foi possível concluir agora. Tente novamente.']);
}
