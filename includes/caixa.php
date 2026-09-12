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

        $stmt = $pdo->prepare('SELECT valor_total, status, id_cliente, id_usuario, origem FROM vendas WHERE id_venda = :id FOR UPDATE');
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
        // Pagamento não pode registrar mais do que a venda vale — troco (Dinheiro) tem
        // que ser calculado e descontado ANTES de chegar aqui (feito em caixa/pagamento.php).
        // Sem esta trava, um valor "recebido" maior que o total vira receita fantasma no
        // caixa (foi exatamente o bug: cliente pagou R$5 numa venda de R$2, os R$3 de troco
        // nunca saíram do valor registrado, e o fechamento esperava R$5 a mais no caixa).
        if ($total_pago > (float) $venda['valor_total'] + 0.01) {
            throw new Exception('Valor pago maior que o total da venda — calcule o troco antes de finalizar.');
        }

        $itens = $pdo->prepare('SELECT id_produto_variacao, quantidade FROM itens_venda WHERE id_venda = :id');
        $itens->execute([':id' => $id_venda]);
        $listaItens = $itens->fetchAll();

        if (empty($listaItens)) {
            throw new Exception('Não é possível finalizar uma venda sem itens.');
        }

        $quantidadePorVariacao = [];
        foreach ($listaItens as $item) {
            if ($item['id_produto_variacao'] === null) {
                continue;
            }
            $id_pv = (int) $item['id_produto_variacao'];
            $quantidadePorVariacao[$id_pv] = ($quantidadePorVariacao[$id_pv] ?? 0) + (int) $item['quantidade'];
        }

        // Ordem determinística de aquisição dos locks FOR UPDATE — evita deadlock entre
        // duas finalizarVenda() concorrentes que travem o mesmo conjunto de variações
        // em ordens diferentes.
        ksort($quantidadePorVariacao);

        foreach ($quantidadePorVariacao as $id_pv => $quantidadeTotal) {
            $stmtPv = $pdo->prepare('SELECT estoque FROM produto_variacoes WHERE id_produto_variacao = :id FOR UPDATE');
            $stmtPv->execute([':id' => $id_pv]);
            $pv = $stmtPv->fetch();
            if (!$pv || $pv['estoque'] < $quantidadeTotal) {
                throw new Exception('Estoque insuficiente para um dos itens da venda.');
            }
        }

        foreach ($quantidadePorVariacao as $id_pv => $quantidadeTotal) {
            $pdo->prepare('UPDATE produto_variacoes SET estoque = estoque - :qtd, estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
                ->execute([':qtd' => $quantidadeTotal, ':id' => $id_pv]);
        }

        // Linha de Crédito não é dinheiro recebido — é uma promessa de pagamento que
        // aumenta o saldo devedor do cliente vinculado à venda, até o limite liberado.
        $valorCredito = 0.0;
        foreach ($pagamentos as $pag) {
            if ($pag['forma'] === 'Linha de Crédito') {
                $valorCredito += (float) $pag['valor'];
            }
        }

        if ($valorCredito > 0) {
            if ($valorCredito > (float) $venda['valor_total'] + 0.01) {
                throw new Exception('Valor do crédito maior que o total da venda.');
            }

            if (!$venda['id_cliente']) {
                throw new Exception('É necessário vincular um cliente para vender a prazo.');
            }

            $id_cliente_credito = (int) $venda['id_cliente'];

            $aumentouCredito = $pdo->prepare(
                'UPDATE clientes SET saldo_devedor = saldo_devedor + :valor
                 WHERE id_cliente = :id AND (saldo_devedor + :valor2) <= limite_credito'
            );
            $aumentouCredito->execute([':valor' => $valorCredito, ':valor2' => $valorCredito, ':id' => $id_cliente_credito]);

            if ($aumentouCredito->rowCount() === 0) {
                throw new Exception('Limite de crédito insuficiente.');
            }

            $pdo->prepare(
                "INSERT INTO movimentos_credito (id_cliente, tipo, status, valor, id_venda, criado_por)
                 VALUES (:ic, 'compra', 'Confirmado', :valor, :iv, :criado_por)"
            )->execute([
                ':ic' => $id_cliente_credito,
                ':valor' => $valorCredito,
                ':iv' => $id_venda,
                ':criado_por' => $venda['id_usuario'],
            ]);
        }

        $formas = [];
        foreach ($pagamentos as $pag) {
            $pdo->prepare('INSERT INTO venda_pagamentos (id_venda, forma_pagamento, valor) VALUES (:id, :forma, :valor)')
                ->execute([':id' => $id_venda, ':forma' => $pag['forma'], ':valor' => $pag['valor']]);
            $formas[] = $pag['forma'];
        }
        $forma_pagamento = count(array_unique($formas)) > 1 ? 'Mista' : $formas[0];

        // status_entrega só existe pra pedidos da loja online (pra acompanhar preparo/envio) —
        // venda de balcão no PDV já sai pronta, não tem etapa de preparo pra rastrear.
        $statusEntrega = $venda['origem'] === 'loja' ? 'Aguardando preparo' : null;
        $pdo->prepare('UPDATE vendas SET status = "Pago", forma_pagamento = :forma, id_pagamento_mp = :idmp, status_entrega = :se WHERE id_venda = :id')
            ->execute([':forma' => $forma_pagamento, ':idmp' => $id_pagamento_mp, ':se' => $statusEntrega, ':id' => $id_venda]);

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
