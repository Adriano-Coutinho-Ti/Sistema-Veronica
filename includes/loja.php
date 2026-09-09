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

    foreach ($vendasExpiradas as $id_venda) {
        devolverReservaDaVenda($pdo, (int) $id_venda);
        $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id")
            ->execute([':id' => $id_venda]);
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
