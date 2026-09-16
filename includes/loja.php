<?php
// definirEntregaDaVenda() usa recalcularTotalVenda(), definida em caixa.php.
require_once __DIR__ . '/caixa.php';
require_once __DIR__ . '/email_smtp.php';

/**
 * Libera reservas de carrinho da loja online que passaram do prazo — devolve
 * a reserva de cada item e marca a venda como cancelada. Chamada no início
 * de toda página/endpoint da loja que lê estoque ou carrinho, em vez de
 * depender de um cron job (a liberação só acontece quando alguém acessa o
 * sistema, mas isso é suficiente pra este projeto).
 */
function liberarReservasExpiradas(PDO $pdo): void
{
    // Enquanto existe um pagamento em andamento (pagamento_expira_em preenchido —
    // ver loja/ajax/gerar_checkout.php), o prazo normal do carrinho não vale mais:
    // só o prazo do próprio pagamento (10 min) decide se a venda expirou. Isso evita
    // devolver ao estoque um item que o cliente está no meio de pagar no Mercado
    // Pago. Sem pagamento em andamento, vale a regra de sempre (prazo do carrinho) —
    // a menos que prazo_reserva_minutos seja 0 (carrinho livre, sem cronômetro),
    // caso em que o item nunca expira sozinho por tempo; o prazo de pagamento do
    // Mercado Pago continua valendo normalmente mesmo com o carrinho livre.
    $stmt = $pdo->prepare(
        "SELECT v.id_venda
         FROM vendas v
         JOIN config_loja cl ON cl.id_config = 1
         WHERE v.status = 'Reservado' AND v.origem = 'loja'
           AND (
                (v.pagamento_expira_em IS NULL AND cl.prazo_reserva_minutos > 0 AND v.data_venda < DATE_SUB(NOW(), INTERVAL cl.prazo_reserva_minutos MINUTE))
                OR (v.pagamento_expira_em IS NOT NULL AND v.pagamento_expira_em < NOW())
           )"
    );
    $stmt->execute();
    $vendasExpiradas = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Cancelar e devolver a reserva têm que acontecer juntos: se o processo morresse entre os
    // dois, a reserva ficaria presa pra sempre (nenhuma varredura posterior pega a venda de
    // novo, porque ela já não está mais 'Reservado'). Cada venda vai na sua própria transação;
    // o guard do inTransaction() mantém a função segura se algum chamador já tiver aberto uma.
    foreach ($vendasExpiradas as $id_venda) {
        cancelarVendaReservadaLoja($pdo, (int) $id_venda);
    }
}

/**
 * Cancela uma venda 'Reservado' da loja online e devolve a reserva de
 * estoque — usada tanto pela varredura automática de expiração acima quanto
 * pelo cancelamento manual de um pagamento pendente (pedidos/ajax/
 * cancelar_pagamento_mp.php). Retorna true só se realmente cancelou (a venda
 * pode já ter mudado de status por outro caminho concorrente, ex.: o
 * webhook confirmando o pagamento bem na hora).
 */
function cancelarVendaReservadaLoja(PDO $pdo, int $id_venda): bool
{
    $jaEmTransacao = $pdo->inTransaction();
    if (!$jaEmTransacao) {
        $pdo->beginTransaction();
    }
    try {
        $cancelou = $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id AND status = 'Reservado'");
        $cancelou->execute([':id' => $id_venda]);
        $cancelouDeVerdade = $cancelou->rowCount() > 0;
        if ($cancelouDeVerdade) {
            devolverReservaDaVenda($pdo, $id_venda);
        }
        if (!$jaEmTransacao) {
            $pdo->commit();
        }
        return $cancelouDeVerdade;
    } catch (Throwable $e) {
        if (!$jaEmTransacao && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
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
        // liberado_em marca o instante em que a peça voltou a ficar disponível — é o que
        // permite ao catálogo avisar em tempo real quem está navegando (ver
        // loja/ajax/verificar_novidades.php), mesmo sem essa pessoa ter mexido no carrinho.
        $pdo->prepare('UPDATE produto_variacoes SET estoque_reservado = GREATEST(0, estoque_reservado - :qtd), liberado_em = NOW() WHERE id_produto_variacao = :id')
            ->execute([':qtd' => $item['quantidade'], ':id' => $item['id_produto_variacao']]);
    }
}

/**
 * Busca o carrinho "Reservado" ainda em montagem deste cliente na loja
 * online, se houver — NÃO inclui uma venda que já foi pro Mercado Pago
 * (pagamento_expira_em preenchido): a partir do momento que o cliente clica
 * em pagar, aquela venda vira um pedido de verdade (visível em
 * meus_pedidos.php como "Aguardando pagamento") e deixa de ser "o carrinho";
 * adicionar um novo item nesse momento cria um carrinho novo do zero (ver
 * loja/ajax/adicionar_item.php), em vez de voltar a mexer num pedido que já
 * está em pagamento.
 */
function buscarCarrinhoDoCliente(PDO $pdo, int $id_cliente): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id_venda FROM vendas WHERE id_cliente = :ic AND origem = 'loja' AND status = 'Reservado' AND pagamento_expira_em IS NULL ORDER BY data_venda DESC LIMIT 1"
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

/**
 * Monta o link "wa.me" pra compartilhar um produto num grupo/contato do
 * WhatsApp — sem número de destino, então abre o seletor de conversa do
 * próprio WhatsApp. O texto traz nome e preço direto na mensagem (não
 * depende só da prévia do link, que demora pra carregar ou pode falhar
 * em alguns grupos); a prévia bonita com foto vem das tags og:* que
 * loja/produto.php imprime no <head>, lidas pelo crawler do WhatsApp
 * quando alguém abre/reenvia o link.
 */
function montarLinkCompartilharWhatsapp(string $nome, float $preco, string $urlProduto): string
{
    $precoFormatado = number_format($preco, 2, ',', '.');
    $texto = "🛍️ *{$nome}*\nR$ {$precoFormatado}\n\n{$urlProduto}";
    return 'https://api.whatsapp.com/send?text=' . urlencode($texto);
}

/**
 * Formata o WhatsApp da loja (guardado como só dígitos, ex: "5534996536637")
 * pra exibição — usado no rodapé. Se não bater com o formato esperado (BR,
 * DDI+DDD+9 dígitos), devolve como veio pra nunca esconder um número salvo.
 */
function formatarWhatsappExibicao(string $whatsapp): string
{
    if (preg_match('/^55(\d{2})(\d{5})(\d{4})$/', $whatsapp, $m)) {
        return '+55 (' . $m[1] . ') ' . $m[2] . '-' . $m[3];
    }
    return $whatsapp;
}

/**
 * Mesmo formato que formatarTelefoneBr() (assets/js/loja.js) produz enquanto o
 * cliente digita — usado pra pré-preencher um campo EDITÁVEL de WhatsApp
 * (ex: Minha conta) sem o "+55" do formatarWhatsappExibicao() acima, que
 * quebraria a máscara JS se ficasse no valor inicial do campo (o "55" do
 * "+55" seria lido como dois dígitos do número pela máscara).
 */
function formatarWhatsappParaEdicao(string $whatsapp): string
{
    $digitos = preg_replace('/\D/', '', $whatsapp);
    if (strlen($digitos) > 11 && str_starts_with($digitos, '55')) {
        $digitos = substr($digitos, 2);
    }
    if (strlen($digitos) < 10) {
        return $whatsapp;
    }
    $ddd = substr($digitos, 0, 2);
    $resto = substr($digitos, 2);
    $tamanhoParte1 = strlen($digitos) > 10 ? 5 : 4;
    $parte1 = substr($resto, 0, $tamanhoParte1);
    $parte2 = substr($resto, $tamanhoParte1);
    return '(' . $ddd . ') ' . $parte1 . ($parte2 !== '' ? '-' . $parte2 : '');
}

/**
 * True só quando o cliente já clicou no link do e-mail de verificação. Usado
 * pra bloquear carrinho/adicionar-ao-carrinho de contas com e-mail ainda não
 * confirmado — evita cadastro com e-mail falso ("conta fantasma").
 */
function clienteEmailVerificado(PDO $pdo, int $id_cliente): bool
{
    $stmt = $pdo->prepare('SELECT email_verificado_em FROM clientes WHERE id_cliente = :id');
    $stmt->execute([':id' => $id_cliente]);
    return $stmt->fetchColumn() !== null;
}

/**
 * Gera um token de verificação (válido por 24h), salva no cliente e manda o
 * e-mail com o link de confirmação. Chamada tanto no cadastro novo quanto na
 * ativação de conta existente (e de novo sempre que o cliente troca de
 * e-mail em "Minha conta") — em todos os casos o e-mail está, até esse
 * ponto, um dado não confirmado.
 */
function dispararVerificacaoEmail(PDO $pdo, int $id_cliente, string $email, string $nome): array
{
    $token = bin2hex(random_bytes(32));
    $expiraEm = date('Y-m-d H:i:s', time() + 86400); // 24h

    $pdo->prepare('UPDATE clientes SET token_verificacao_email = :t, token_verificacao_expira_em = :e WHERE id_cliente = :id')
        ->execute([':t' => $token, ':e' => $expiraEm, ':id' => $id_cliente]);

    $configLoja = $pdo->query('SELECT nome_loja FROM config_loja WHERE id_config = 1')->fetch();
    $nomeLoja = $configLoja['nome_loja'] ?? 'a loja';

    $link = urlBaseAtual() . '/loja/verificar_email.php?token=' . $token;
    $primeiroNome = explode(' ', trim($nome))[0];

    $corpo = montarEmailHtmlLoja(
        $pdo,
        'Confirme seu e-mail',
        [
            'Olá, ' . htmlspecialchars($primeiroNome) . '!',
            'Recebemos esse e-mail como o seu de contato na <strong>' . htmlspecialchars($nomeLoja) . '</strong>. Confirme clicando no botão abaixo — assim garantimos que é você mesmo, e você já pode usar o carrinho de compras.',
            'Este link é válido por <strong>24 horas</strong>.',
        ],
        'Confirmar meu e-mail',
        $link
    );

    $resultado = enviarEmailSMTP($pdo, $email, 'Confirme seu e-mail — ' . $nomeLoja, $corpo);
    if (!$resultado['success']) {
        error_log('Falha ao enviar e-mail de verificação (cliente ' . $id_cliente . '): ' . $resultado['message']);
    }
    return $resultado;
}

/**
 * Processa o webhook do Mercado Pago pro fluxo da loja online (checkout) —
 * chamado pelo endpoint único em integracoes/mercado_pago/webhook.php quando
 * o external_reference do pagamento começa com "loja_".
 */
function processarWebhookVendaLoja(PDO $pdo, int $id_venda, array $pagamento, string $dataId): void
{
    $statusPagamento = $pagamento['status'] ?? null;

    if ($statusPagamento === 'approved') {
        $stmt = $pdo->prepare("SELECT status FROM vendas WHERE id_venda = :id AND origem = 'loja'");
        $stmt->execute([':id' => $id_venda]);
        $venda = $stmt->fetch();

        if ($venda && $venda['status'] === 'Reservado') {
            $valorPago = (float) ($pagamento['transaction_amount'] ?? 0);
            $resultado = finalizarVenda($pdo, $id_venda, [['forma' => 'Mercado Pago', 'valor' => $valorPago]], $dataId);

            if (!$resultado['success']) {
                error_log('Webhook loja MP: falha ao finalizar venda ' . $id_venda . ': ' . $resultado['message']);

                // Só falta de estoque real cancela a venda automaticamente — nesse caso não
                // tem como entregar o pedido, então liberar a reserva é o certo.
                //
                // O Mercado Pago pode entregar a mesma notificação mais de uma vez.
                // Se duas chamadas concorrentes chegarem aqui, o FOR UPDATE dentro de
                // finalizarVenda() garante que só uma finalize a venda — a outra recebe
                // success:false só porque perdeu a corrida. Por isso o UPDATE guardado
                // roda primeiro: só quem realmente transiciona Reservado -> Cancelado
                // (rowCount() > 0) é que devolve a reserva. Isso evita devolver estoque
                // que já foi legitimamente consumido pela chamada vencedora.
                if (str_contains($resultado['message'], 'Estoque insuficiente')) {
                    $cancelou = $pdo->prepare("UPDATE vendas SET status = 'Cancelado' WHERE id_venda = :id AND status = 'Reservado'");
                    $cancelou->execute([':id' => $id_venda]);
                    if ($cancelou->rowCount() > 0) {
                        devolverReservaDaVenda($pdo, $id_venda);
                    }
                }
                // Outros motivos de falha (pagamento parcial/insuficiente, venda já finalizada por uma
                // notificação concorrente, erro transitório de lock) NÃO cancelam a venda automaticamente —
                // ficam só registrados no log acima. A venda continua 'Reservado', podendo ainda ser
                // finalizada por uma notificação subsequente (ex.: segunda parte de um pagamento dividido)
                // ou expirar normalmente pelo prazo de reserva se for realmente abandonada.
            }
        }
    } elseif (in_array($statusPagamento, ['rejected', 'cancelled'], true)) {
        // Cartão recusado, Pix cancelado/expirado no lado do Mercado Pago etc. — não é
        // culpa do cliente ter chegado primeiro no produto, então ele ganha um novo
        // prazo de reserva inteiro pra tentar de novo (novo Pix, outro cartão) em vez
        // de perder o item na hora. Só reinicia venda que ainda está 'Reservado' — se
        // já foi finalizada ou cancelada por outro caminho, não mexe em nada.
        $pdo->prepare(
            "UPDATE vendas SET data_venda = NOW(), pagamento_expira_em = NULL
             WHERE id_venda = :id AND origem = 'loja' AND status = 'Reservado'"
        )->execute([':id' => $id_venda]);
    }
}
