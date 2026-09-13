<?php
/**
 * Comprovante público — sem login, protegido só pelo token na URL (link
 * mandado por WhatsApp). Layout inspirado no projeto sys01 (caixa/recibo.php
 * de lá): card bonito na tela, mas com @media print que estreita pra
 * largura de bobina térmica (~80mm) e troca pra fonte monoespaçada — a
 * MESMA página serve pro link do WhatsApp e pra impressão, sem precisar
 * de um arquivo separado só pra impressão.
 */
require_once __DIR__ . '/../conecta_bd.php';

$id_venda = (int) ($_GET['id_venda'] ?? 0);
$token = $_GET['t'] ?? '';

$stmt = $pdo->prepare(
    "SELECT v.*, c.nome AS cliente_nome
     FROM vendas v
     LEFT JOIN clientes c ON c.id_cliente = v.id_cliente
     WHERE v.id_venda = :id AND v.status = 'Pago'"
);
$stmt->execute([':id' => $id_venda]);
$venda = $stmt->fetch();

if (!$venda || $token === '' || !hash_equals((string) $venda['token_recibo'], $token)) {
    http_response_code(404);
    echo 'Comprovante não encontrado.';
    exit;
}

$itens = $pdo->prepare('SELECT * FROM itens_venda WHERE id_venda = :id ORDER BY id_item');
$itens->execute([':id' => $id_venda]);
$listaItens = $itens->fetchAll();

$pagamentos = $pdo->prepare('SELECT * FROM venda_pagamentos WHERE id_venda = :id');
$pagamentos->execute([':id' => $id_venda]);
$listaPagamentos = $pagamentos->fetchAll();

$configLoja = $pdo->query('SELECT nome_loja, whatsapp_loja, endereco_loja FROM config_loja WHERE id_config = 1')->fetch();
$imprimirAuto = isset($_GET['print']) && $_GET['print'] === '1';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Comprovante #<?= $id_venda ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Manrope:wght@400;500;600;700&display=swap">
<style>
    :root { --cor-primaria: #4F46E5; --cor-texto: #1F2937; --cor-texto-suave: #6b7280; --cor-borda: #e5e7eb; }
    * { box-sizing: border-box; }
    body {
        margin: 0; padding: 24px 14px; background: #eef0fb;
        font-family: 'Manrope', -apple-system, sans-serif; color: var(--cor-texto);
    }
    .recibo { max-width: 400px; margin: 0 auto; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(30,41,59,0.14); overflow: hidden; }
    .recibo-cabecalho { background: var(--cor-primaria); color: #fff; padding: 22px 20px; text-align: center; }
    .recibo-cabecalho strong { font-family: 'Fraunces', Georgia, serif; font-size: 1.2rem; display: block; margin-bottom: 4px; }
    .recibo-cabecalho span { font-size: 0.82rem; opacity: 0.9; display: block; }
    .recibo-corpo { padding: 20px; }
    .recibo-meta { font-size: 0.85rem; color: var(--cor-texto-suave); margin-bottom: 16px; }
    .linha-item { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px dashed var(--cor-borda); font-size: 0.9rem; }
    .linha-item small { display: block; color: var(--cor-texto-suave); font-size: 0.78rem; margin-top: 2px; }
    .recibo-total { display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 1.25rem; padding-top: 14px; margin-top: 6px; border-top: 2px solid var(--cor-texto); font-family: 'Fraunces', Georgia, serif; }
    .recibo-corpo h4 { margin: 20px 0 8px; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--cor-texto-suave); }
    .recibo-rodape { text-align: center; padding: 18px; color: var(--cor-texto-suave); font-size: 0.85rem; border-top: 1px solid var(--cor-borda); }
    .btn-imprimir {
        display: block; width: 100%; margin-top: 22px; padding: 12px; border: none; border-radius: 10px;
        background: var(--cor-primaria); color: #fff; font-weight: 700; font-size: 0.95rem; cursor: pointer;
    }
    @media print {
        body { background: #fff; padding: 0; }
        .no-print { display: none !important; }
        .recibo { max-width: 300px; width: 100%; margin: 0 auto; box-shadow: none; border-radius: 0; font-family: 'Courier New', Courier, monospace; }
        .recibo-cabecalho { background: #fff; color: #000; padding: 8px 0; }
        .recibo-corpo { padding: 10px; }
        .linha-item { font-size: 12px; }
        .recibo-total { font-family: 'Courier New', Courier, monospace; }
    }
</style>
</head>
<body>
    <div class="recibo">
        <div class="recibo-cabecalho">
            <strong><?= htmlspecialchars($configLoja['nome_loja'] ?? 'Brechó da Veve') ?></strong>
            <?php if (!empty($configLoja['endereco_loja'])): ?><span><?= htmlspecialchars($configLoja['endereco_loja']) ?></span><?php endif; ?>
        </div>
        <div class="recibo-corpo">
            <p class="recibo-meta">
                Venda #<?= $id_venda ?> · <?= htmlspecialchars(date('d/m/Y H:i', strtotime($venda['data_venda']))) ?><br>
                <?= $venda['cliente_nome'] ? 'Cliente: ' . htmlspecialchars($venda['cliente_nome']) : 'Consumidor' ?>
            </p>

            <?php foreach ($listaItens as $item): ?>
            <div class="linha-item">
                <span>
                    <?= (int) $item['quantidade'] ?>x <?= htmlspecialchars($item['nome_produto']) ?>
                    <?php if ($item['descricao_combinacao']): ?><small><?= htmlspecialchars($item['descricao_combinacao']) ?></small><?php endif; ?>
                </span>
                <span>R$ <?= number_format($item['subtotal'], 2, ',', '.') ?></span>
            </div>
            <?php endforeach; ?>

            <div class="recibo-total"><span>Total</span><span>R$ <?= number_format($venda['valor_total'], 2, ',', '.') ?></span></div>

            <h4>Pagamento</h4>
            <?php foreach ($listaPagamentos as $pag): ?>
            <div class="linha-item"><span><?= htmlspecialchars($pag['forma_pagamento']) ?></span><span>R$ <?= number_format($pag['valor'], 2, ',', '.') ?></span></div>
            <?php endforeach; ?>

            <button type="button" class="btn-imprimir no-print" onclick="window.print()">Imprimir comprovante</button>
        </div>
        <div class="recibo-rodape">Obrigado pela preferência! 💙</div>
    </div>

<?php if ($imprimirAuto): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
</body>
</html>
