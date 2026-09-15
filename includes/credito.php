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
