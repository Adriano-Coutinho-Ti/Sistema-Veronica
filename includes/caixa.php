<?php

/**
 * Quantos caixas físicos o sistema trabalha -- configurável no painel_dev.
 * 1 (o default) é o comportamento original: um único caixa pro sistema
 * inteiro, sem tela de seleção.
 */
function quantidadeCaixas(PDO $pdo): int
{
    $qtd = $pdo->query('SELECT quantidade_caixas FROM config_dev WHERE id_config = 1')->fetchColumn();
    $qtd = $qtd !== false && $qtd !== null ? (int) $qtd : 1;
    return max(1, $qtd);
}

/**
 * Se um caixa aberto pode ser atendido por mais de um usuário ao mesmo
 * tempo. Só tem efeito prático quando quantidadeCaixas() > 1.
 */
function caixasCompartilhados(PDO $pdo): bool
{
    $valor = $pdo->query('SELECT caixas_compartilhados FROM config_dev WHERE id_config = 1')->fetchColumn();
    return $valor === false || $valor === null ? true : (bool) $valor;
}

/**
 * Com um único caixa (config padrão), devolve o único caixa aberto do
 * sistema -- exatamente como sempre funcionou. Com mais de um caixa,
 * devolve o caixa que O USUÁRIO LOGADO selecionou em caixa/selecionar.php
 * (guardado em $_SESSION['id_caixa_selecionado']), revalidando que ele
 * continua aberto e que o usuário ainda pode operar nele (se caixas não são
 * compartilhados, só quem abriu pode usar).
 */
function caixaAbertoAtual(PDO $pdo): ?array
{
    if (quantidadeCaixas($pdo) <= 1) {
        $stmt = $pdo->query("SELECT * FROM caixa_sessoes WHERE status = 'aberto' ORDER BY data_abertura DESC LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    $idCaixaSelecionado = $_SESSION['id_caixa_selecionado'] ?? null;
    if (!$idCaixaSelecionado) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM caixa_sessoes WHERE id_caixa = :id AND status = 'aberto'");
    $stmt->execute([':id' => $idCaixaSelecionado]);
    $caixa = $stmt->fetch();

    if (!$caixa) {
        unset($_SESSION['id_caixa_selecionado']);
        return null;
    }

    if (!caixasCompartilhados($pdo) && (int) $caixa['aberto_por'] !== (int) ($_SESSION['id_usuario'] ?? 0)) {
        unset($_SESSION['id_caixa_selecionado']);
        return null;
    }

    return $caixa;
}

function exigirCaixaAberto(PDO $pdo, bool $modo_json = false): array
{
    $caixa = caixaAbertoAtual($pdo);
    if (!$caixa) {
        if ($modo_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Nenhum caixa aberto.']);
        } else {
            header('Location: ' . (quantidadeCaixas($pdo) > 1 ? '/caixa/selecionar.php' : '/caixa/abertura.php'));
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
            $stmtPv = $pdo->prepare(
                'SELECT pv.estoque, p.estoque_gerenciado FROM produto_variacoes pv
                 JOIN produtos p ON p.id_produto = pv.id_produto
                 WHERE pv.id_produto_variacao = :id FOR UPDATE'
            );
            $stmtPv->execute([':id' => $id_pv]);
            $pv = $stmtPv->fetch();
            // Produto com estoque não controlado (checkbox desmarcado em
            // produtos/editar.php) pode ser vendido livremente, sem checar nem
            // descontar as quantidades de produto_variacoes.
            if (!$pv || ((int) $pv['estoque_gerenciado'] && $pv['estoque'] < $quantidadeTotal)) {
                throw new Exception('Estoque insuficiente para um dos itens da venda.');
            }
            if (!(int) $pv['estoque_gerenciado']) {
                unset($quantidadePorVariacao[$id_pv]);
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
        // Token do comprovante público (caixa/recibo.php) — gerado aqui, na hora que a venda
        // vira "Pago" de verdade, pra já estar pronto assim que o operador for compartilhar.
        $tokenRecibo = bin2hex(random_bytes(20));
        $pdo->prepare('UPDATE vendas SET status = "Pago", forma_pagamento = :forma, id_pagamento_mp = :idmp, status_entrega = :se, token_recibo = :token WHERE id_venda = :id')
            ->execute([':forma' => $forma_pagamento, ':idmp' => $id_pagamento_mp, ':se' => $statusEntrega, ':token' => $tokenRecibo, ':id' => $id_venda]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => 'Venda finalizada com sucesso.',
            'redirect' => '/caixa/comprovante.php?id_venda=' . $id_venda . '&novo=1',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage(), 'redirect' => null];
    }
}

/**
 * Processa o webhook do Mercado Pago pro fluxo do PDV (Pix no balcão) —
 * chamado pelo endpoint único em integracoes/mercado_pago/webhook.php quando
 * o external_reference do pagamento começa com "venda_".
 */
function processarWebhookVendaCaixa(PDO $pdo, int $id_venda, array $pagamento, string $dataId): void
{
    if (($pagamento['status'] ?? null) !== 'approved') {
        return;
    }

    $stmt = $pdo->prepare('SELECT valor_total, status FROM vendas WHERE id_venda = :id');
    $stmt->execute([':id' => $id_venda]);
    $venda = $stmt->fetch();

    if ($venda && $venda['status'] === 'Reservado') {
        // Usa o valor que o Mercado Pago confirma ter recebido (transaction_amount),
        // não uma releitura do total atual da venda — ver mesma nota em
        // verificar_pagamento.php.
        $valorPago = (float) ($pagamento['transaction_amount'] ?? 0);
        $resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Pix', 'valor' => $valorPago]], $dataId);
        if (!$resultado['success']) {
            error_log('Webhook MP: falha ao finalizar venda ' . $id_venda . ': ' . $resultado['message']);
        }
    }
}

/**
 * Processa o webhook do Mercado Pago pro fluxo de pagamento por maquininha
 * (Point, tópico "order") — diferente do Pix porque o recurso é uma Order,
 * não um Payment: status/status_detail ficam na raiz ("processed" +
 * "accredited" = pago), não "approved", e o valor pago vem de
 * transactions.payments[0].amount.
 */
function processarWebhookVendaCaixaPoint(PDO $pdo, int $id_venda, array $order, string $orderId): void
{
    $status = $order['status'] ?? null;
    $statusDetail = $order['status_detail'] ?? null;

    $stmt = $pdo->prepare('SELECT status FROM vendas WHERE id_venda = :id');
    $stmt->execute([':id' => $id_venda]);
    $venda = $stmt->fetch();

    if (!$venda || $venda['status'] !== 'Reservado') {
        return;
    }

    if ($status === 'processed' && $statusDetail === 'accredited') {
        $valorPago = (float) ($order['transactions']['payments'][0]['amount'] ?? 0);
        $resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Cartão (maquininha)', 'valor' => $valorPago]], $orderId);
        if (!$resultado['success']) {
            error_log('Webhook MP (Point): falha ao finalizar venda ' . $id_venda . ': ' . $resultado['message']);
        }
    } elseif (in_array($status, ['canceled', 'expired'], true)) {
        // Libera a maquininha pra outra venda — a venda continua "Reservado",
        // o operador pode tentar de novo ou escolher outra forma de pagamento.
        $pdo->prepare("UPDATE vendas SET id_order_mp = NULL WHERE id_venda = :id AND status = 'Reservado'")
            ->execute([':id' => $id_venda]);
    }
}
