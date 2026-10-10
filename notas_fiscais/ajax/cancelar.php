<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/fiscal.php';
exigirAdmin();
header('Content-Type: application/json');

try {
    fiscalCancelar($pdo, (int) ($_POST['id_nota'] ?? 0), (string) ($_POST['motivo'] ?? ''), (string) ($_SESSION['nome'] ?? 'Admin'));
    echo json_encode(['success' => true, 'message' => 'Nota cancelada.']);
} catch (FiscalErro $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('notas_fiscais/cancelar: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Não foi possível cancelar agora. Tente novamente.']);
}
