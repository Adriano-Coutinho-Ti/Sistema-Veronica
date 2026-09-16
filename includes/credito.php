<?php

/**
 * Calcula, por compra (mais antiga primeiro), quanto ainda está em aberto e
 * quanto disso já venceu — sem vincular pagamento a nenhuma compra no banco,
 * já que o cliente não escolhe qual dívida está pagando. Tudo que ele já
 * pagou (tipo='pagamento', status='Confirmado') é abatido das compras da
 * mais antiga pra mais nova (FIFO); o que sobrar de cada compra depois
 * desse abatimento é o valor em aberto dela, e esse valor conta como
 * vencido se a data da compra + $prazoDiasCredito já passou.
 *
 * Sempre calculado na hora, nunca guardado — assim o "vencido" acompanha a
 * data de hoje sozinho, e mudar o prazo de um cliente recalcula o histórico
 * dele inteiro também, sem precisar reprocessar nada no banco.
 */
function calcularSituacaoCreditoCliente(PDO $pdo, int $id_cliente, int $prazoDiasCredito): array
{
    $stmtCompras = $pdo->prepare(
        "SELECT id_movimento, data_movimento, valor FROM movimentos_credito
         WHERE id_cliente = :id AND tipo = 'compra' AND status = 'Confirmado'
         ORDER BY data_movimento ASC, id_movimento ASC"
    );
    $stmtCompras->execute([':id' => $id_cliente]);
    $compras = $stmtCompras->fetchAll();

    $stmtPago = $pdo->prepare(
        "SELECT COALESCE(SUM(valor), 0) FROM movimentos_credito
         WHERE id_cliente = :id AND tipo = 'pagamento' AND status = 'Confirmado'"
    );
    $stmtPago->execute([':id' => $id_cliente]);
    $saldoParaAbater = (float) $stmtPago->fetchColumn();

    $hoje = new DateTimeImmutable('today');
    $totalAberto = 0.0;
    $totalVencido = 0.0;
    $comprasComSituacao = [];

    foreach ($compras as $c) {
        $valorCompra = (float) $c['valor'];
        $abatido = min($valorCompra, $saldoParaAbater);
        $saldoParaAbater -= $abatido;
        $emAberto = round($valorCompra - $abatido, 2);

        $dataVencimento = (new DateTimeImmutable($c['data_movimento']))->modify('+' . $prazoDiasCredito . ' days');
        $diasAtraso = ($emAberto > 0 && $hoje > $dataVencimento) ? $hoje->diff($dataVencimento)->days : 0;
        $vencido = $diasAtraso > 0 ? $emAberto : 0.0;

        $totalAberto += $emAberto;
        $totalVencido += $vencido;

        $comprasComSituacao[(int) $c['id_movimento']] = [
            'em_aberto' => $emAberto,
            'vencido' => $vencido,
            'dias_atraso' => $diasAtraso,
            'data_vencimento' => $dataVencimento->format('Y-m-d'),
        ];
    }

    return [
        'total_aberto' => round($totalAberto, 2),
        'total_vencido' => round($totalVencido, 2),
        'compras' => $comprasComSituacao,
    ];
}

/**
 * Processa o webhook do Mercado Pago pro fluxo de pagamento de dívida
 * (Linha de Crédito) — chamado pelo endpoint único em
 * integracoes/mercado_pago/webhook.php quando o external_reference do
 * pagamento começa com "divida_".
 */
function processarWebhookDivida(PDO $pdo, int $id_movimento, array $pagamento, string $dataId): void
{
    $statusPagamento = $pagamento['status'] ?? null;

    $stmt = $pdo->prepare("SELECT id_cliente, valor, status FROM movimentos_credito WHERE id_movimento = :id AND tipo = 'pagamento'");
    $stmt->execute([':id' => $id_movimento]);
    $movimento = $stmt->fetch();

    if (!$movimento || $movimento['status'] !== 'Pendente') {
        return;
    }

    if ($statusPagamento === 'approved') {
        // Observabilidade: só registramos divergência entre o valor reportado pelo MP e
        // o esperado (não bloqueia a confirmação).
        $valorReportadoMp = (float) ($pagamento['transaction_amount'] ?? 0);
        if (abs($valorReportadoMp - (float) $movimento['valor']) > 0.01) {
            error_log('Webhook divida MP: valor reportado pelo MP (' . $valorReportadoMp . ') diverge do valor registrado (' . $movimento['valor'] . ') para id_movimento=' . $id_movimento);
        }

        // Confirmar o movimento e abater o saldo têm que acontecer juntos: se o segundo
        // UPDATE falhasse, o movimento ficaria 'Confirmado' sem nunca abater a dívida —
        // e o catch externo responde 200, então nada re-tentaria.
        $pdo->beginTransaction();
        try {
            $confirmou = $pdo->prepare(
                "UPDATE movimentos_credito SET status = 'Confirmado', id_pagamento_mp = :idmp
                 WHERE id_movimento = :id AND status = 'Pendente'"
            );
            $confirmou->execute([':idmp' => $dataId, ':id' => $id_movimento]);

            if ($confirmou->rowCount() > 0) {
                $abateu = $pdo->prepare(
                    'UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor)
                     WHERE id_cliente = :id AND saldo_devedor >= :valor2'
                );
                $abateu->execute([':valor' => $movimento['valor'], ':valor2' => $movimento['valor'], ':id' => $movimento['id_cliente']]);

                if ($abateu->rowCount() === 0) {
                    error_log('Webhook divida MP: saldo_devedor já estava abaixo do valor confirmado (id_movimento=' . $id_movimento . ', valor=' . $movimento['valor'] . ')');
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    } elseif (in_array($statusPagamento, ['rejected', 'cancelled'], true)) {
        $pdo->prepare(
            "UPDATE movimentos_credito SET status = 'Cancelado', id_pagamento_mp = :idmp
             WHERE id_movimento = :id AND status = 'Pendente'"
        )->execute([':idmp' => $dataId, ':id' => $id_movimento]);
    }
}
