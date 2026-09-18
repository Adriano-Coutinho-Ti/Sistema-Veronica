<?php
/**
 * Lixeira de clientes. "Excluir" só marca clientes.excluido_em -- o cliente
 * some das listas/PDV e perde o acesso à loja (includes/auth_cliente.php,
 * loja/cadastro.php), mas nada é apagado. A exclusão definitiva leva junto
 * todos os registros dele e só é permitida pra quem já está na lixeira.
 * Quem chama já garante que o usuário logado é Admin.
 */
require_once __DIR__ . '/loja.php';

function moverClienteParaLixeira(PDO $pdo, int $id_cliente, int $id_usuario): bool
{
    $pdo->beginTransaction();
    try {
        // Carrinho aberto na loja/PDV segura estoque reservado -- libera antes
        // do cliente sumir, senão essa reserva ficaria presa pra sempre.
        $abertas = $pdo->prepare("SELECT id_venda FROM vendas WHERE id_cliente = :id AND status = 'Reservado'");
        $abertas->execute([':id' => $id_cliente]);
        foreach ($abertas->fetchAll(PDO::FETCH_COLUMN) as $id_venda) {
            cancelarVendaReservadaLoja($pdo, (int) $id_venda);
        }

        $upd = $pdo->prepare('UPDATE clientes SET excluido_em = NOW(), excluido_por = :u WHERE id_cliente = :id AND excluido_em IS NULL');
        $upd->execute([':u' => $id_usuario, ':id' => $id_cliente]);
        $ok = $upd->rowCount() > 0;
        $pdo->commit();
        return $ok;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function restaurarClienteDaLixeira(PDO $pdo, int $id_cliente): bool
{
    $upd = $pdo->prepare('UPDATE clientes SET excluido_em = NULL, excluido_por = NULL WHERE id_cliente = :id AND excluido_em IS NOT NULL');
    $upd->execute([':id' => $id_cliente]);
    return $upd->rowCount() > 0;
}

/**
 * Apaga o cliente e tudo que aponta pra ele. itens_venda e venda_pagamentos
 * caem sozinhos junto com a venda (ON DELETE CASCADE). Tudo numa transação:
 * se qualquer tabela recusar, nada é apagado.
 */
function excluirClientePermanentemente(PDO $pdo, int $id_cliente): bool
{
    $confere = $pdo->prepare('SELECT 1 FROM clientes WHERE id_cliente = :id AND excluido_em IS NOT NULL');
    $confere->execute([':id' => $id_cliente]);
    if (!$confere->fetchColumn()) {
        return false;
    }

    $pdo->beginTransaction();
    try {
        $abertas = $pdo->prepare("SELECT id_venda FROM vendas WHERE id_cliente = :id AND status = 'Reservado'");
        $abertas->execute([':id' => $id_cliente]);
        foreach ($abertas->fetchAll(PDO::FETCH_COLUMN) as $id_venda) {
            cancelarVendaReservadaLoja($pdo, (int) $id_venda);
        }

        // movimentos_credito aponta pra vendas -- precisa sair antes delas.
        foreach (['movimentos_credito', 'favoritos', 'solicitacoes_credito', 'vendas'] as $tabela) {
            $pdo->prepare("DELETE FROM $tabela WHERE id_cliente = :id")->execute([':id' => $id_cliente]);
        }
        $pdo->prepare('DELETE FROM clientes WHERE id_cliente = :id AND excluido_em IS NOT NULL')->execute([':id' => $id_cliente]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
