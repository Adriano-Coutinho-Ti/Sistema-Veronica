<?php

/**
 * Dois travamentos do sistema, mutuamente exclusivos (config_dev.travamento_ativo
 * só aceita um valor por vez), controlados só pelo painel_dev:
 * - "pagamento": bloqueia só o sistema de gestão (Fundação/PDV/Linha de
 *   Crédito) quando o lojista está com pagamento em aberto -- a loja online
 *   continua funcionando normalmente pros clientes. Checado em toda página
 *   protegida por exigirLogin(), não só no momento do login -- uma sessão já
 *   aberta antes do travamento ser ativado também é barrada.
 * - "manutencao": bloqueia TUDO, inclusive a loja online e a própria tela de
 *   login. Checado em toda requisição (chamado a partir de conecta_bd.php).
 *   Só painel_dev (senão o próprio dev ficaria trancado do lado de fora sem
 *   conseguir desligar) e o webhook do Mercado Pago (senão pagamentos em
 *   andamento perdem a confirmação) ficam de fora.
 */

function verificarTravamentoManutencao(?PDO $pdo): void
{
    if (!$pdo) {
        return;
    }

    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with($uri, '/painel_dev/')
        || str_starts_with($uri, '/integracoes/mercado_pago/webhook.php')
        || str_starts_with($uri, '/manutencao.php')) {
        return;
    }

    $config = $pdo->query("SELECT travamento_ativo FROM config_dev WHERE id_config = 1")->fetch();
    if ($config && $config['travamento_ativo'] === 'manutencao') {
        header('Location: /manutencao.php');
        exit;
    }
}

function verificarTravamentoPagamento(PDO $pdo): void
{
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_starts_with($uri, '/sistema_bloqueado.php')) {
        return;
    }

    $config = $pdo->query("SELECT travamento_ativo FROM config_dev WHERE id_config = 1")->fetch();
    if ($config && $config['travamento_ativo'] === 'pagamento') {
        header('Location: /sistema_bloqueado.php');
        exit;
    }
}
