<?php

/**
 * Libera reservas de carrinho da loja online que passaram do prazo — devolve
 * a reserva de cada item e marca a venda como cancelada. Chamada no início
 * de toda página/endpoint da loja que lê estoque ou carrinho, em vez de
 * depender de um cron job (a liberação só acontece quando alguém acessa o
 * sistema, mas isso é suficiente pra este projeto).
 */
function liberarReservasExpiradas(PDO $pdo): void
{
    $stmt = $pdo->prepare(
        "SELECT v.id_venda
         FROM vendas v
         JOIN config_loja cl ON cl.id_config = 1
         WHERE v.status = 'Reservado' AND v.origem = 'loja'
           AND v.data_venda < DATE_SUB(NOW(), INTERVAL cl.prazo_reserva_minutos MINUTE)"
    );
    $stmt->execute();
    $vendasExpiradas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Cancelar e devolver a reserva têm que acontecer juntos: se o processo morresse entre os
    // dois, a reserva ficaria presa pra sempre (nenhuma varredura posterior pega a venda de
    // novo, porque ela já não está mais 'Reservado'). Cada venda vai na sua própria transação;
    // o guard do inTransaction() mantém a função segura se algum chamador já tiver aberto uma.
    foreach ($vendasExpiradas as $id_venda) {
        $jaEmTransacao = $pdo->inTransaction();
        if (!$jaEmTransacao) {
            $pdo->beginTransaction();
        }
        try {
            $cancelou = $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id AND status = 'Reservado'");
            $cancelou->execute([':id' => $id_venda]);
            if ($cancelou->rowCount() > 0) {
                devolverReservaDaVenda($pdo, (int) $id_venda);
            }
            if (!$jaEmTransacao) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if (!$jaEmTransacao && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

/**
 * Devolve a reserva (estoque_reservado) de todos os itens de uma venda —
 * reaproveitada pela expiração automática, pela remoção explícita de um
 * item do carrinho, e por uma falha de finalização vinda do webhook.
 */
function devolverReservaDaVenda(PDO $pdo, int $id_venda): void
{
    $stmt = $pdo->prepare('SELECT id_produto_variacao, quantidade FROM itens_venda WHERE id_venda = :id');
    $stmt->execute([':id' => $id_venda]);
    foreach ($stmt->fetchAll() as $item) {
        if ($item['id_produto_variacao'] === null) {
            continue;
        }
        $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - :qtd) WHERE id_produto_variacao = :id')
            ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);
    }
}

/**
 * Busca a venda "Reservado" em andamento deste cliente na loja online, se
 * houver.
 */
function buscarCarrinhoDoCliente(PDO $pdo, int $id_cliente): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id_venda FROM vendas WHERE id_cliente = :ic AND origem = 'loja' AND status = 'Reservado' ORDER BY data_venda DESC LIMIT 1"
    );
    $stmt->execute([':ic' => $id_cliente]);
    $id = $stmt->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Grava/substitui a linha de entrega da venda — um item sem produto vinculado
 * (id_produto_variacao NULL), que finalizarVenda()/devolverReservaDaVenda() já
 * ignoram — e recalcula o total. Reaproveitado tanto pelo checkout via Mercado
 * Pago quanto pelo pagamento direto com Linha de Crédito, pra nunca duplicar essa
 * lógica entre os dois fluxos.
 */
function definirEntregaDaVenda(PDO $pdo, int $id_venda, array $entrega): float
{
    $pdo->prepare("DELETE FROM itens_venda WHERE id_venda = :iv AND id_produto_variacao IS NULL AND nome_produto = 'Entrega'")
        ->execute([':iv' => $id_venda]);

    if ((float) $entrega['custo'] > 0) {
        $pdo->prepare(
            'INSERT INTO itens_venda (id_venda, nome_produto, descricao_combinacao, id_produto_variacao, quantidade, preco_unit, subtotal)
             VALUES (:iv, :nome, :desc, NULL, 1, :preco, :subtotal)'
        )->execute([
            ':iv' => $id_venda,
            ':nome' => 'Entrega',
            ':desc' => $entrega['nome'],
            ':preco' => (float) $entrega['custo'],
            ':subtotal' => (float) $entrega['custo'],
        ]);
    }

    $pdo->prepare('UPDATE vendas SET id_entrega = :ie WHERE id_venda = :iv')
        ->execute([':ie' => (int) $entrega['id_entrega'], ':iv' => $id_venda]);

    return recalcularTotalVenda($pdo, $id_venda);
}
