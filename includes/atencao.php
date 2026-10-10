<?php
require_once __DIR__ . '/mp_client.php';
require_once __DIR__ . '/whatsapp_conexao.php';

/**
 * "Precisa de atenção" do Dashboard: em vez de rotina agendada (cron), as
 * checagens de tempo rodam quando o administrador abre o Dashboard.
 *
 * - Mercado Pago: se o token está perto de vencer (30 dias), renova sozinho com o
 *   refresh_token; só vira alerta se não conseguir renovar ou se já venceu.
 * - WhatsApp: se o dev habilitou o recurso mas a loja não tem número conectado.
 */

const MP_RENOVAR_COM_DIAS = 30;

/**
 * Renova o token do Mercado Pago quando faltam 30 dias ou menos (ou já venceu).
 * Tenta uma vez por sessão de login, pra uma falha não atrasar todo carregamento.
 *
 * @return array{estado:string, dias:?int} estado: nao_conectado | ok | renovado | falhou | expirado
 */
function mpRenovarTokenSeNecessario(PDO $pdo): array
{
    $c = $pdo->query(
        'SELECT mp_access_token, mp_refresh_token, mp_token_expira, TIMESTAMPDIFF(HOUR, NOW(), mp_token_expira) AS horas FROM config_pagamento WHERE id_config = 1'
    )->fetch();
    if (!$c || empty($c['mp_access_token'])) {
        return ['estado' => 'nao_conectado', 'dias' => null];
    }
    if ($c['mp_token_expira'] === null) {
        return ['estado' => 'ok', 'dias' => null]; // o Mercado Pago não informou validade: nada a fazer
    }
    $horas = (int) $c['horas'];
    $dias = (int) floor($horas / 24);
    if ($dias > MP_RENOVAR_COM_DIAS) {
        return ['estado' => 'ok', 'dias' => $dias];
    }

    $ja = $_SESSION['mp_renovacao_tentada'] ?? false;
    if (!$ja && !empty($c['mp_refresh_token'])) {
        $_SESSION['mp_renovacao_tentada'] = true;
        if (mpTrocarRefreshToken($pdo, (string) $c['mp_refresh_token'])) {
            return ['estado' => 'renovado', 'dias' => null];
        }
    }

    return ['estado' => $horas <= 0 ? 'expirado' : 'falhou', 'dias' => max(0, $dias)];
}

/** Pede um token novo ao Mercado Pago com o refresh_token e grava. true se deu certo. */
function mpTrocarRefreshToken(PDO $pdo, string $refreshToken): bool
{
    $app = mpAppCredenciais($pdo);
    if (empty($app['client_id']) || empty($app['client_secret'])) {
        return false;
    }
    $ch = curl_init(defined('MP_OAUTH_URL') ? MP_OAUTH_URL : 'https://api.mercadopago.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resposta = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($resposta === false || $http < 200 || $http >= 300) {
        error_log('mpTrocarRefreshToken: HTTP ' . $http . ($resposta === false ? ' ' . curl_error($ch) : ''));
        return false;
    }
    $d = json_decode((string) $resposta, true);
    if (!is_array($d) || empty($d['access_token'])) {
        return false;
    }
    $pdo->prepare(
        'UPDATE config_pagamento SET mp_access_token = :at, mp_refresh_token = COALESCE(:rt, mp_refresh_token), mp_public_key = COALESCE(:pk, mp_public_key),
                mp_token_expira = IF(:seg > 0, DATE_ADD(NOW(), INTERVAL :seg2 SECOND), mp_token_expira) WHERE id_config = 1'
    )->execute([
        ':at' => $d['access_token'], ':rt' => $d['refresh_token'] ?? null, ':pk' => $d['public_key'] ?? null,
        ':seg' => (int) ($d['expires_in'] ?? 0), ':seg2' => (int) ($d['expires_in'] ?? 0),
    ]);

    return true;
}

/**
 * Itens que precisam da atenção do administrador agora.
 *
 * @return array<int,array{titulo:string, texto:string, link:string, rotulo:string, nivel:string}>
 */
function atencaoDoSistema(PDO $pdo): array
{
    $itens = [];
    $mp = mpRenovarTokenSeNecessario($pdo);
    if ($mp['estado'] === 'nao_conectado') {
        $itens[] = [
            'titulo' => 'Mercado Pago não conectado',
            'texto' => 'A loja online e o PDV não conseguem receber pagamentos por Pix e cartão até a conta ser conectada.',
            'link' => '/integracoes/mercado_pago/conectar.php', 'rotulo' => 'Conectar agora', 'nivel' => 'erro',
        ];
    } elseif ($mp['estado'] === 'expirado') {
        $itens[] = [
            'titulo' => 'Conexão com o Mercado Pago vencida',
            'texto' => 'O acesso ao Mercado Pago venceu e não foi possível renovar sozinho. Enquanto isso, os pagamentos por Pix e cartão não funcionam. Conecte a conta de novo.',
            'link' => '/integracoes/mercado_pago/conectar.php', 'rotulo' => 'Reconectar agora', 'nivel' => 'erro',
        ];
    } elseif ($mp['estado'] === 'falhou') {
        $itens[] = [
            'titulo' => 'Conexão com o Mercado Pago perto de vencer',
            'texto' => 'Faltam ' . (int) $mp['dias'] . ' dia(s) para o acesso ao Mercado Pago vencer e a renovação automática não funcionou. Reconecte a conta antes disso para não parar de receber pagamentos.',
            'link' => '/integracoes/mercado_pago/conectar.php', 'rotulo' => 'Reconectar agora', 'nivel' => 'alerta',
        ];
    }

    try {
        if (whatsappModuloDevAtivo($pdo) && !whatsappAtivo($pdo)) {
            $row = whatsappConexaoLer($pdo);
            $jaConectou = $row !== null && (string) $row['instancia'] !== '';
            $itens[] = [
                'titulo' => $jaConectou ? 'WhatsApp da loja desconectado' : 'WhatsApp da loja ainda não conectado',
                'texto' => 'Sem um número de WhatsApp conectado, os clientes só conseguem validar e entrar pelo e-mail.'
                    . ($jaConectou ? ' A conexão caiu (o celular pode ter saído da sessão).' : ''),
                'link' => '/config_sistema/whatsapp.php', 'rotulo' => $jaConectou ? 'Reconectar' : 'Conectar WhatsApp', 'nivel' => 'alerta',
            ];
        }
    } catch (Throwable $e) {
        error_log('atencaoDoSistema/whatsapp: ' . $e->getMessage());
    }

    return $itens;
}
