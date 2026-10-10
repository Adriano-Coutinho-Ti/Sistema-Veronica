<?php
require_once __DIR__ . '/evolution.php';

/**
 * WhatsApp da loja: o lojista conecta o próprio número por QR code (servidor
 * Evolution do desenvolvedor) e pode desconectar/trocar quando quiser.
 *
 * Regra de negócio: o recurso de WhatsApp (validação e login do cliente pelo
 * número) só existe quando o dev habilitou no painel_dev E o lojista tem um
 * número CONECTADO. Sem número conectado o sistema funciona como se o
 * WhatsApp não existisse (só e-mail) — não há número de apoio do dev.
 */

const WHATSAPP_ACEITE_TEXTO = 'Esta conexão utiliza uma API para WhatsApp (de terceiros), e não a API oficial do WhatsApp. O sistema segue as regras de não spam e não recomendamos envio de mensagens em massa nem disparos, pois isso pode gerar o banimento do seu número de WhatsApp. Use um número dedicado à loja e envie apenas mensagens a clientes que esperam o contato.';

// De quanto em quanto tempo (segundos) o sistema confere na Evolution se a loja ainda está conectada.
const WHATSAPP_VERIFICAR_A_CADA_S = 300;

/**
 * Nome da instância da loja no servidor Evolution: o domínio do sistema (ex.: "brechodaveve-codernex-com-br"),
 * que identifica a loja entre várias no mesmo servidor. Em localhost/IP cai no nome da loja.
 */
function whatsappNomeInstancia(PDO $pdo): string
{
    $host = strtolower(explode(':', dominioAtual())[0]);
    $host = preg_replace('/^www\./', '', $host);
    if ($host === 'localhost' || preg_match('/^[\d.]+$/', $host) === 1) {
        $host = mb_strtolower((string) $pdo->query('SELECT nome_loja FROM config_loja WHERE id_config = 1')->fetchColumn());
    }
    // Tira acentos sem depender de iconv/intl (o resultado deles muda de servidor pra servidor).
    $semAcento = strtr($host, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
    ]);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($semAcento)), '-');

    return substr($slug !== '' ? $slug : 'loja', 0, 45);
}

/** Nome automático antigo ("loja-" + 8 hexadecimais), de antes de a instância ter nome legível. */
function whatsappNomeInstanciaAntigo(string $nome): bool
{
    return preg_match('/^loja-[0-9a-f]{8}$/', $nome) === 1;
}

/** O dev habilitou o recurso de WhatsApp (e deixou o servidor Evolution preenchido)? */
function whatsappModuloDevAtivo(PDO $pdo): bool
{
    $c = $pdo->query('SELECT whatsapp_verificacao_ativo, evolution_base_url, evolution_api_key FROM config_dev WHERE id_config = 1')->fetch();
    return $c && (int) $c['whatsapp_verificacao_ativo'] === 1 && trim((string) $c['evolution_base_url']) !== '' && trim((string) $c['evolution_api_key']) !== '';
}

/** Linha única da conexão da loja, ou null se a tabela ainda não existe. */
function whatsappConexaoLer(PDO $pdo): ?array
{
    try {
        return $pdo->query(
            'SELECT *, TIMESTAMPDIFF(SECOND, verificado_em, NOW()) AS segundos_desde_verificacao FROM whatsapp_conexao WHERE id = 1'
        )->fetch() ?: null;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return null;
        }
        throw $e;
    }
}

/**
 * Recurso de WhatsApp efetivamente ligado agora: dev habilitou + loja conectada.
 * Usada no lugar de config_dev.whatsapp_verificacao_ativo em todo o sistema.
 * De tempos em tempos confere na Evolution se a conexão ainda está de pé (se o
 * celular desconectou a sessão, o recurso desliga sozinho).
 */
function whatsappAtivo(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        if (!whatsappModuloDevAtivo($pdo)) {
            return $cache = false;
        }
        $row = whatsappConexaoLer($pdo);
    } catch (Throwable $e) {
        return $cache = false;
    }
    if ($row === null || $row['estado'] !== 'conectado' || (string) $row['instancia'] === '') {
        return $cache = false;
    }

    if ($row['segundos_desde_verificacao'] === null || (int) $row['segundos_desde_verificacao'] >= WHATSAPP_VERIFICAR_A_CADA_S) {
        // Marca antes de consultar: evita que várias requisições simultâneas consultem juntas.
        $pdo->exec('UPDATE whatsapp_conexao SET verificado_em = NOW() WHERE id = 1');
        try {
            $g = evolutionConfigGlobal($pdo);
            if (evolutionEstado($g['base'], $g['chave'], (string) $row['instancia'], 4) === 'desconectado') {
                $pdo->exec("UPDATE whatsapp_conexao SET estado = 'desconectado', numero = NULL, conectado_em = NULL WHERE id = 1");
                return $cache = false;
            }
        } catch (Throwable $e) {
            // Servidor fora do ar não derruba o recurso: só é desligado quando a Evolution diz que desconectou.
            error_log('whatsappAtivo: ' . $e->getMessage());
        }
    }
    return $cache = true;
}

/** Instância da loja pronta pra enviar mensagem, ou null se não houver conexão ativa. */
function whatsappInstanciaAtiva(PDO $pdo): ?string
{
    if (!whatsappAtivo($pdo)) {
        return null;
    }
    return (string) whatsappConexaoLer($pdo)['instancia'];
}

function whatsappConexaoAceiteOk(?array $row): bool
{
    return $row !== null && !empty($row['aceite_em']);
}

function whatsappConexaoAceitar(PDO $pdo, int $idUsuario): void
{
    $pdo->prepare('UPDATE whatsapp_conexao SET aceite_em = NOW(), aceite_por = :u WHERE id = 1')->execute([':u' => $idUsuario]);
}

/**
 * Cria (uma única vez) a instância da loja e devolve o QR code; se já existe
 * (ex.: depois de desconectar), só pede um QR novo.
 *
 * @return array{estado:string,qr:?string,codigo:?string}
 */
function whatsappConexaoConectar(PDO $pdo): array
{
    if (!whatsappModuloDevAtivo($pdo)) {
        throw new InvalidArgumentException('A conexão do WhatsApp não está habilitada para esta loja.');
    }
    $row = whatsappConexaoLer($pdo);
    if ($row === null) {
        throw new RuntimeException('O banco ainda não tem a tabela do WhatsApp. Avise o desenvolvedor.');
    }
    if (!whatsappConexaoAceiteOk($row)) {
        throw new InvalidArgumentException('Aceite os termos antes de conectar o WhatsApp.');
    }
    $g = evolutionConfigGlobal($pdo);

    $travou = (int) $pdo->query("SELECT GET_LOCK('loja_wa_conectar', 30)")->fetchColumn() === 1;
    if (!$travou) {
        throw new RuntimeException('Já existe uma conexão sendo criada. Aguarde alguns segundos e atualize a página.');
    }
    try {
        $nome = (string) $row['instancia'];
        $qr = $codigo = null;
        if ($nome !== '' && !evolutionExiste($g['base'], $g['chave'], $nome)) {
            $nome = '';
        }
        // Instância com o nome automático antigo e sem número conectado: apaga e recria com o nome legível.
        if ($nome !== '' && whatsappNomeInstanciaAntigo($nome) && evolutionEstado($g['base'], $g['chave'], $nome) !== 'conectado') {
            evolutionRemover($g['base'], $g['chave'], $nome);
            $nome = '';
        }
        if ($nome === '') {
            $novo = evolutionCriarInstancia($g['base'], $g['chave'], whatsappNomeInstancia($pdo));
            $nome = $novo['instancia'];
            $qr = $novo['qr'];
            $codigo = $novo['codigo'];
            $pdo->prepare("UPDATE whatsapp_conexao SET instancia = :i, estado = 'conectando', numero = NULL, conectado_em = NULL WHERE id = 1")->execute([':i' => $nome]);
        } elseif (evolutionEstado($g['base'], $g['chave'], $nome) === 'conectado') {
            $pdo->prepare("UPDATE whatsapp_conexao SET estado = 'conectado', numero = :n, conectado_em = COALESCE(conectado_em, NOW()), verificado_em = NOW() WHERE id = 1")
                ->execute([':n' => evolutionNumero($g['base'], $g['chave'], $nome)]);
            return ['estado' => 'conectado', 'qr' => null, 'codigo' => null];
        }
        if ($qr === null) {
            try {
                $q = evolutionQr($g['base'], $g['chave'], $nome);
            } catch (EvolutionNaoEncontrada) {
                $novo = evolutionCriarInstancia($g['base'], $g['chave'], whatsappNomeInstancia($pdo));
                $nome = $novo['instancia'];
                $q = ['qr' => $novo['qr'], 'codigo' => $novo['codigo']];
                $pdo->prepare('UPDATE whatsapp_conexao SET instancia = :i WHERE id = 1')->execute([':i' => $nome]);
            }
            $qr = $q['qr'];
            $codigo = $q['codigo'];
        }
        $pdo->exec("UPDATE whatsapp_conexao SET estado = 'conectando' WHERE id = 1");
        return ['estado' => 'conectando', 'qr' => $qr, 'codigo' => $codigo];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('loja_wa_conectar')")->fetchAll();
    }
}

/** Consulta a Evolution e atualiza o estado guardado. @return array{estado:string,numero:?string,erro:?string} */
function whatsappConexaoEstado(PDO $pdo): array
{
    $row = whatsappConexaoLer($pdo);
    $nome = (string) ($row['instancia'] ?? '');
    $base = ['estado' => (string) ($row['estado'] ?? 'desconectado'), 'numero' => $row['numero'] ?? null, 'erro' => null];
    if ($row === null || $nome === '') {
        return ['estado' => 'desconectado', 'numero' => null, 'erro' => null];
    }
    try {
        $g = evolutionConfigGlobal($pdo);
        $estado = evolutionEstado($g['base'], $g['chave'], $nome);
        $numero = $base['numero'];
        if ($estado === 'conectado') {
            $numero = $numero ?: evolutionNumero($g['base'], $g['chave'], $nome);
            $pdo->prepare("UPDATE whatsapp_conexao SET estado = 'conectado', numero = :n, conectado_em = COALESCE(conectado_em, NOW()), verificado_em = NOW() WHERE id = 1")->execute([':n' => $numero]);
        } elseif ($estado === 'desconectado' && $row['estado'] === 'conectando') {
            $estado = 'conectando'; // ainda esperando a leitura do QR
        } elseif ($estado !== $row['estado']) {
            $pdo->prepare('UPDATE whatsapp_conexao SET estado = :e, numero = NULL, conectado_em = NULL WHERE id = 1')->execute([':e' => $estado]);
            $numero = null;
        }
        return ['estado' => $estado, 'numero' => $numero, 'erro' => null];
    } catch (Throwable $e) {
        return $base + ['erro' => 'Não foi possível consultar o servidor de WhatsApp agora.'];
    }
}

/** Desconecta o número (a instância continua; um novo QR conecta outro número). */
function whatsappConexaoDesconectar(PDO $pdo): void
{
    $nome = (string) (whatsappConexaoLer($pdo)['instancia'] ?? '');
    if ($nome !== '') {
        $g = evolutionConfigGlobal($pdo);
        evolutionLogout($g['base'], $g['chave'], $nome);
    }
    $pdo->exec("UPDATE whatsapp_conexao SET estado = 'desconectado', numero = NULL, conectado_em = NULL WHERE id = 1");
}

/**
 * Apaga a instância no servidor (se der) e zera a conexão local, inclusive o
 * aceite. Usada quando o dev desliga o recurso. @return ?string aviso, se a
 * instância não pôde ser removida do servidor.
 */
function whatsappConexaoRemover(PDO $pdo): ?string
{
    $row = whatsappConexaoLer($pdo);
    if ($row === null) {
        return null;
    }
    $aviso = null;
    $nome = (string) ($row['instancia'] ?? '');
    if ($nome !== '') {
        try {
            $g = evolutionConfigGlobal($pdo);
            evolutionRemover($g['base'], $g['chave'], $nome);
        } catch (Throwable $e) {
            $aviso = 'Não foi possível remover a instância "' . $nome . '" no servidor Evolution. Remova-a manualmente no Manager da Evolution.';
        }
    }
    $pdo->exec("UPDATE whatsapp_conexao SET instancia = NULL, estado = 'desconectado', numero = NULL, conectado_em = NULL, aceite_em = NULL, aceite_por = NULL WHERE id = 1");
    return $aviso;
}
