<?php

function caixaAbertoAtual(PDO $pdo): ?array
{
    $stmt = $pdo->query("SELECT * FROM caixa_sessoes WHERE status = 'aberto' ORDER BY data_abertura DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function exigirCaixaAberto(PDO $pdo, bool $modo_json = false): array
{
    $caixa = caixaAbertoAtual($pdo);
    if (!$caixa) {
        if ($modo_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Nenhum caixa aberto.']);
        } else {
            header('Location: /caixa/abertura.php');
        }
        exit;
    }
    return $caixa;
}

function buscarVendaReservadaDoOperador(PDO $pdo, int $id_caixa, int $id_usuario): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id_venda FROM vendas
         WHERE id_caixa = :ic AND id_usuario = :iu AND status = 'Reservado'
         ORDER BY data_venda DESC LIMIT 1"
    );
    $stmt->execute([':ic' => $id_caixa, ':iu' => $id_usuario]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

function recalcularTotalVenda(PDO $pdo, int $id_venda): float
{
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal), 0) FROM itens_venda WHERE id_venda = :id');
    $stmt->execute([':id' => $id_venda]);
    $total = (float) $stmt->fetchColumn();
    $pdo->prepare('UPDATE vendas SET valor_total = :total WHERE id_venda = :id')
        ->execute([':total' => $total, ':id' => $id_venda]);
    return $total;
}

/**
 * Núcleo de finalização de venda — reaproveitado pelo botão "Finalizar" (pagamento
 * manual), pelo polling do Pix e pelo webhook do Mercado Pago, pra nunca duplicar a
 * baixa de estoque/gravação de pagamento em mais de um lugar.
 *
 * $pagamentos: array de ['forma' => string, 'valor' => float]
 */
function finalizarVenda(PDO $pdo, int $id_venda, array $pagamentos, ?string $id_pagamento_mp = null): array
{
    if (empty($pagamentos)) {
        return ['success' => false, 'message' => 'Informe ao menos uma forma de pagamento.', 'redirect' => null];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT valor_total, status FROM vendas WHERE id_venda = :id FOR UPDATE');
        $stmt->execute([':id' => $id_venda]);
        $venda = $stmt->fetch();

        if (!$venda || $venda['status'] !== 'Reservado') {
            throw new Exception('Venda não encontrada ou já finalizada.');
        }

        $total_pago = 0.0;
        foreach ($pagamentos as $pag) {
            $total_pago += (float) $pag['valor'];
        }
        if ($total_pago < (float) $venda['valor_total'] - 0.01) {
            throw new Exception('Valor pago insuficiente.');
        }

        $itens = $pdo->prepare('SELECT id_produto_variacao, quantidade FROM itens_venda WHERE id_venda = :id');
        $itens->execute([':id' => $id_venda]);
        $listaItens = $itens->fetchAll();

        if (empty($listaItens)) {
            throw new Exception('Não é possível finalizar uma venda sem itens.');
        }

        foreach ($listaItens as $item) {
            if ($item['id_produto_variacao'] === null) {
                continue;
            }
            $stmtPv = $pdo->prepare('SELECT estoque FROM produto_variacoes WHERE id_produto_variacao = :id FOR UPDATE');
            $stmtPv->execute([':id' => $item['id_produto_variacao']]);
            $pv = $stmtPv->fetch();
            if (!$pv || $pv['estoque'] < $item['quantidade']) {
                throw new Exception('Estoque insuficiente para um dos itens da venda.');
            }
        }

        foreach ($listaItens as $item) {
            if ($item['id_produto_variacao'] === null) {
                continue;
            }
            $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque - :qtd WHERE id_produto_variacao = :id')
                ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);
        }

        $formas = [];
        foreach ($pagamentos as $pag) {
            $pdo->prepare('INSERT INTO venda_pagamentos (id_venda, forma_pagamento, valor) VALUES (:id, :forma, :valor)')
                ->execute([':id' => $id_venda, ':forma' => $pag['forma'], ':valor' => $pag['valor']]);
            $formas[] = $pag['forma'];
        }
        $forma_pagamento = count(array_unique($formas)) > 1 ? 'Mista' : $formas[0];

        $pdo->prepare('UPDATE vendas SET status = "Pago", forma_pagamento = :forma, id_pagamento_mp = :idmp WHERE id_venda = :id')
            ->execute([':forma' => $forma_pagamento, ':idmp' => $id_pagamento_mp, ':id' => $id_venda]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Venda finalizada com sucesso.',
            'redirect' => '/caixa/comprovante.php?id_venda=' . $id_venda,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage(), 'redirect' => null];
    }
}
