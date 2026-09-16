<?php

const STATUS_ENTREGA_VALIDOS = ['Aguardando preparo', 'Preparando', 'Pronto', 'Enviado', 'Entregue'];

/**
 * Cancela um pedido da loja online já PAGO: devolve o estoque baixado na
 * finalização e, se parte do pagamento foi em Linha de Crédito, estorna o
 * saldo devedor do cliente (mesmo padrão de UPDATE + INSERT em
 * movimentos_credito usado no registro manual de pagamento de dívida).
 * Pagamento em Dinheiro/Débito/Crédito/Pix continua tendo que ser
 * reembolsado por fora do sistema — não existe estorno automático real de
 * cartão/Pix aqui.
 */
function cancelarPedidoPago(PDO $pdo, int $id_venda, int $id_usuario_admin, string $motivo): array
{
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT status, id_cliente FROM vendas WHERE id_venda = :id FOR UPDATE');
        $stmt->execute([':id' => $id_venda]);
        $venda = $stmt->fetch();

        if (!$venda || $venda['status'] !== 'Pago') {
            throw new Exception('Pedido não encontrado ou não está pago.');
        }

        $itens = $pdo->prepare('SELECT id_produto_variacao, quantidade FROM itens_venda WHERE id_venda = :id');
        $itens->execute([':id' => $id_venda]);

        $porVariacao = [];
        foreach ($itens->fetchAll() as $item) {
            if ($item['id_produto_variacao'] === null) {
                continue;
            }
            $id_pv = (int) $item['id_produto_variacao'];
            $porVariacao[$id_pv] = ($porVariacao[$id_pv] ?? 0) + (int) $item['quantidade'];
        }
        ksort($porVariacao);
        // Produto com estoque não controlado nunca teve nada descontado na
        // finalização — devolver aqui só inflaria o número à toa.
        foreach ($porVariacao as $id_pv => $qtd) {
            $stmtGerenciado = $pdo->prepare(
                'SELECT p.estoque_gerenciado FROM produto_variacoes pv JOIN produtos p ON p.id_produto = pv.id_produto WHERE pv.id_produto_variacao = :id'
            );
            $stmtGerenciado->execute([':id' => $id_pv]);
            if (!(int) $stmtGerenciado->fetchColumn()) {
                continue;
            }
            $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque + :qtd, liberado_em = NOW() WHERE id_produto_variacao = :id')
                ->execute([':qtd' => $qtd, ':id' => $id_pv]);
        }

        $stmtCredito = $pdo->prepare(
            "SELECT COALESCE(SUM(valor), 0) FROM venda_pagamentos WHERE id_venda = :id AND forma_pagamento = 'Linha de Crédito'"
        );
        $stmtCredito->execute([':id' => $id_venda]);
        $valorCredito = (float) $stmtCredito->fetchColumn();

        if ($valorCredito > 0 && $venda['id_cliente']) {
            $pdo->prepare('UPDATE clientes SET saldo_devedor = GREATEST(0, saldo_devedor - :valor) WHERE id_cliente = :id')
                ->execute([':valor' => $valorCredito, ':id' => $venda['id_cliente']]);

            $pdo->prepare(
                "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, id_venda, forma_pagamento, criado_por)
                 VALUES (:ic, 'pagamento', 'Confirmado', :valor, :iv, 'Estorno de cancelamento', :criado_por)"
            )->execute([
                ':ic' => $venda['id_cliente'],
                ':valor' => $valorCredito,
                ':iv' => $id_venda,
                ':criado_por' => $id_usuario_admin,
            ]);
        }

        $pdo->prepare("UPDATE vendas SET status = 'Cancelado', status_entrega = NULL, motivo_cancelamento = :motivo WHERE id_venda = :id")
            ->execute([':motivo' => $motivo, ':id' => $id_venda]);

        $pdo->commit();

        return ['success' => true, 'message' => 'Pedido cancelado. Estoque e crédito (se houver) foram estornados.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function rotuloStatusPedido(string $status, ?string $status_entrega, ?string $tipoEntrega = null): string
{
    if ($status === 'Cancelado') {
        return 'Cancelado';
    }
    if ($status === 'Reservado') {
        return 'Aguardando pagamento';
    }

    return match ($status_entrega) {
        'Preparando' => 'Preparando seu pedido',
        'Pronto' => $tipoEntrega === 'retirada' ? 'Pronto para retirada na loja' : 'Pronto para envio',
        'Enviado' => 'Enviado — a caminho',
        'Entregue' => $tipoEntrega === 'retirada' ? 'Retirado' : 'Entregue',
        default => 'Pagamento confirmado — na fila de preparo',
    };
}

/**
 * Classe CSS da bolinha de status (.status-pill) — cada etapa tem sua própria
 * cor fixa (ver :root de loja.css) pra dar pra reconhecer o estágio de
 * relance, sem precisar ler o texto.
 */
function classePillStatusPedido(string $status, ?string $status_entrega): string
{
    if ($status === 'Cancelado') {
        return 'cancelado';
    }
    if ($status === 'Reservado') {
        return 'alerta';
    }

    return match ($status_entrega) {
        'Preparando' => 'preparando',
        'Pronto' => 'pronto',
        'Enviado' => 'enviado',
        'Entregue' => 'concluido',
        default => '',
    };
}
