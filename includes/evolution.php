<?php

/**
 * Cliente mínimo da Evolution API (servidor de WhatsApp) — só o que a
 * conexão por QR code do lojista precisa: criar/consultar/desconectar/remover
 * a instância da loja. Usa sempre a chave GLOBAL do servidor (config_dev).
 */

class EvolutionNaoEncontrada extends RuntimeException
{
}

const EVOLUTION_MSG_CHAVE_GLOBAL = 'A chave configurada não tem permissão para criar conexões (HTTP 401). No painel do desenvolvedor, use a chave GLOBAL do servidor (AUTHENTICATION_API_KEY), a mesma que se digita para entrar no Manager da Evolution, e não a chave de uma instância.';

/** @return array{http_code:int,dados:mixed} */
function evolutionChamar(string $base, string $chave, string $metodo, string $caminho, ?array $payload = null, int $timeout = 20): array
{
    $ch = curl_init(rtrim($base, '/') . $caminho);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'apikey: ' . $chave],
    ];
    if ($payload !== null) {
        $opcoes[CURLOPT_POSTFIELDS] = $payload === [] ? '{}' : json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $opcoes);
    $corpo = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro = curl_error($ch);

    if ($corpo === false) {
        throw new RuntimeException('Sem conexão com o servidor de WhatsApp: ' . str_replace($chave, '***', $erro));
    }
    return ['http_code' => $codigo, 'dados' => json_decode((string) $corpo, true)];
}

function evolutionExigir(array $r, string $acao): void
{
    $c = $r['http_code'];
    if ($c >= 200 && $c < 300) {
        return;
    }
    if ($c === 401) {
        throw new RuntimeException('O servidor de WhatsApp recusou a chave (HTTP 401) ao ' . $acao . '. Avise o desenvolvedor do sistema para conferir a chave da Evolution.');
    }
    throw new RuntimeException('Não foi possível ' . $acao . ' (HTTP ' . $c . ').');
}

/** @return array{base:string,chave:string} credenciais globais da Evolution (config_dev). */
function evolutionConfigGlobal(PDO $pdo): array
{
    $c = $pdo->query('SELECT evolution_base_url, evolution_api_key FROM config_dev WHERE id_config = 1')->fetch() ?: [];
    $base = trim((string) ($c['evolution_base_url'] ?? ''));
    $chave = trim((string) ($c['evolution_api_key'] ?? ''));
    if ($base === '' || $chave === '') {
        throw new RuntimeException('O servidor de WhatsApp (Evolution API) ainda não foi configurado pelo desenvolvedor do sistema.');
    }
    return ['base' => $base, 'chave' => $chave];
}

/**
 * Cria a instância da loja. $preferido é o nome legível (ex.: o domínio do sistema); se já existir
 * no servidor, tenta de novo com um sufixo curto. Sem $preferido usa um nome aleatório "loja-xxxxxxxx".
 *
 * @return array{instancia:string,qr:?string,codigo:?string}
 */
function evolutionCriarInstancia(string $base, string $chave, ?string $preferido = null): array
{
    for ($tentativa = 0; $tentativa < 3; $tentativa++) {
        $nome = $preferido
            ? ($tentativa === 0 ? $preferido : substr($preferido, 0, 40) . '-' . bin2hex(random_bytes(2)))
            : 'loja-' . bin2hex(random_bytes(4));
        $r = evolutionChamar($base, $chave, 'POST', '/instance/create', ['instanceName' => $nome, 'qrcode' => true, 'integration' => 'WHATSAPP-BAILEYS']);
        if ($r['http_code'] === 401) {
            throw new RuntimeException(EVOLUTION_MSG_CHAVE_GLOBAL);
        }
        $texto = strtolower((string) json_encode($r['dados']));
        if ($r['http_code'] === 409 || ($r['http_code'] === 403 && preg_match('/in use|already|exist/', $texto) === 1)) {
            continue; // nome já existe: sorteia outro
        }
        evolutionExigir($r, 'criar a conexão do WhatsApp');
        $d = (array) ($r['dados'] ?? []);
        return ['instancia' => $nome, 'qr' => $d['qrcode']['base64'] ?? null, 'codigo' => $d['qrcode']['pairingCode'] ?? null];
    }
    throw new RuntimeException('Não foi possível criar a conexão do WhatsApp. Tente novamente.');
}

/** @return array{qr:?string,codigo:?string} */
function evolutionQr(string $base, string $chave, string $instancia): array
{
    $r = evolutionChamar($base, $chave, 'GET', '/instance/connect/' . rawurlencode($instancia));
    if ($r['http_code'] === 404) {
        throw new EvolutionNaoEncontrada('A conexão não existe mais no servidor.');
    }
    evolutionExigir($r, 'gerar o QR code');
    $d = (array) ($r['dados'] ?? []);
    return ['qr' => $d['base64'] ?? ($d['qrcode']['base64'] ?? null), 'codigo' => $d['pairingCode'] ?? ($d['qrcode']['pairingCode'] ?? null)];
}

/** @return 'conectado'|'conectando'|'desconectado' */
function evolutionEstado(string $base, string $chave, string $instancia, int $timeout = 20): string
{
    $r = evolutionChamar($base, $chave, 'GET', '/instance/connectionState/' . rawurlencode($instancia), null, $timeout);
    if ($r['http_code'] === 404) {
        return 'desconectado';
    }
    evolutionExigir($r, 'consultar a conexão');
    $d = (array) ($r['dados'] ?? []);
    $estado = (string) ($d['instance']['state'] ?? $d['state'] ?? 'close');
    return ['open' => 'conectado', 'connecting' => 'conectando'][$estado] ?? 'desconectado';
}

function evolutionExiste(string $base, string $chave, string $instancia): bool
{
    $r = evolutionChamar($base, $chave, 'GET', '/instance/connectionState/' . rawurlencode($instancia));
    if ($r['http_code'] === 404) {
        return false;
    }
    evolutionExigir($r, 'consultar a conexão');
    return true;
}

/** Número (só dígitos) do WhatsApp conectado na instância, se o servidor informar. */
function evolutionNumero(string $base, string $chave, string $instancia): ?string
{
    $r = evolutionChamar($base, $chave, 'GET', '/instance/fetchInstances?instanceName=' . rawurlencode($instancia));
    if ($r['http_code'] < 200 || $r['http_code'] >= 300) {
        return null;
    }
    $d = (array) ($r['dados'] ?? []);
    $primeiro = isset($d[0]) ? (array) $d[0] : $d;
    $jid = (string) ($primeiro['ownerJid'] ?? $primeiro['number'] ?? ($primeiro['instance']['owner'] ?? ''));
    $digitos = preg_replace('/\D+/', '', explode('@', $jid)[0]) ?? '';
    return $digitos === '' ? null : substr($digitos, 0, 20);
}

function evolutionLogout(string $base, string $chave, string $instancia): void
{
    evolutionChamar($base, $chave, 'DELETE', '/instance/logout/' . rawurlencode($instancia));
}

function evolutionRemover(string $base, string $chave, string $instancia): void
{
    evolutionChamar($base, $chave, 'DELETE', '/instance/logout/' . rawurlencode($instancia));
    $r = evolutionChamar($base, $chave, 'DELETE', '/instance/delete/' . rawurlencode($instancia));
    if ($r['http_code'] !== 404) {
        evolutionExigir($r, 'remover a conexão');
    }
}
