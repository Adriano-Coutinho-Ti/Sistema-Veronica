<?php
require_once __DIR__ . '/../../conecta_bd.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/caixa.php';
require_once __DIR__ . '/../../includes/mp_client.php';
exigirLogin();
header('Content-Type: application/json');

$caixa = exigirCaixaAberto($pdo, true);

$id_venda = (int) ($_POST['id_venda'] ?? 0);
$stmt = $pdo->prepare("SELECT valor_total FROM vendas WHERE id_venda = :id AND id_usuario = :iu AND status = 'Reservado'");
$stmt->execute([':id' => $id_venda, ':iu' => $_SESSION['id_usuario']]);
$venda = $stmt->fetch();

if (!$venda) {
    echo json_encode(['success' => false, 'message' => 'Venda não encontrada ou já finalizada.']);
    exit;
}

$stmtTerminal = $pdo->prepare('SELECT terminal_id FROM caixa_terminais_point WHERE numero_caixa = :n');
$stmtTerminal->execute([':n' => $caixa['numero_caixa']]);
$terminalId = $stmtTerminal->fetchColumn();

if (!$terminalId) {
    echo json_encode(['success' => false, 'message' => 'Esse caixa não tem maquininha vinculada. Configure em Painel do dev → Caixas.']);
    exit;
}

// Mesma maquininha não pode receber duas cobranças ao mesmo tempo, mesmo
// vindas de caixas diferentes -- cenário normal de uma loja com uma única
// maquininha atendendo vários caixas.
$stmtOcupada = $pdo->prepare(
    "SELECT v.id_venda FROM vendas v
     JOIN caixa_sessoes cs ON cs.id_caixa = v.id_caixa
     JOIN caixa_terminais_point ct ON ct.numero_caixa = cs.numero_caixa
     WHERE ct.terminal_id = :t AND v.status = 'Reservado' AND v.id_order_mp IS NOT NULL AND v.id_venda != :iv"
);
$stmtOcupada->execute([':t' => $terminalId, ':iv' => $id_venda]);
if ($stmtOcupada->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Essa maquininha está sendo usada por outra venda agora. Aguarde terminar.']);
    exit;
}

$config = mpConfig($pdo);
if (!$config || empty($config['mp_access_token'])) {
    echo json_encode(['success' => false, 'message' => 'Loja não conectada ao Mercado Pago. Conecte em Configurações.']);
    exit;
}

$valor_total = (float) $venda['valor_total'];
$marketplace_fee = ceil($valor_total * (mpTaxaMarketplace($pdo) / 100) * 100) / 100;
$external_reference = 'venda_' . $id_venda;

$payload = [
    'type' => 'point',
    'external_reference' => $external_reference,
    'notification_url' => urlBaseAtual() . '/integracoes/mercado_pago/webhook.php',
    'expiration_time' => 'PT15M',
    // Order (/v1/orders) documenta marketplace_fee na raiz do payload,
    // independente do type -- mesmo campo/local usado no type "qr", que é a
    // mesma família de API.
    'marketplace_fee' => number_format($marketplace_fee, 2, '.', ''),
    'transactions' => [
        'payments' => [
            ['amount' => number_format($valor_total, 2, '.', '')],
        ],
    ],
    'config' => [
        'point' => [
            'terminal_id' => $terminalId,
        ],
    ],
    'integration_data' => [
        'sponsor' => ['id' => '194420711'],
    ],
];

try {
    $resposta = mpChamarApi('POST', 'https://api.mercadopago.com/v1/orders', $payload, $config['mp_access_token'], [
        'X-Idempotency-Key: ' . $external_reference . '-' . bin2hex(random_bytes(6)),
    ]);

    if ($resposta['http_code'] >= 300 || !isset($resposta['dados']['id'])) {
        echo json_encode(['success' => false, 'message' => 'Erro ao cobrar na maquininha: ' . ($resposta['dados']['message'] ?? 'resposta inesperada do Mercado Pago')]);
        exit;
    }

    $pdo->prepare('UPDATE vendas SET id_order_mp = :id WHERE id_venda = :iv')
        ->execute([':id' => $resposta['dados']['id'], ':iv' => $id_venda]);

    echo json_encode(['success' => true, 'id_order_mp' => $resposta['dados']['id']]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
