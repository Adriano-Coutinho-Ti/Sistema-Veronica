<?php
/**
 * Credenciais do banco: config_credenciais.php (raiz do projeto, do lado do
 * index.php — gitignored, o painel_dev escreve nele quando o desenvolvedor
 * preenche o assistente de conexão em /painel_dev/login.php) tem prioridade.
 * Se ele não existir, cai pro arquivo antigo (fora da pasta pública, formato
 * anterior a este assistente) — só pra instalações já configuradas antes
 * desta mudança continuarem funcionando sem nenhum passo manual.
 */
$arquivoCredenciaisNovo = __DIR__ . '/config_credenciais.php';
$arquivoCredenciaisAntigo = __DIR__ . '/../../brechodaveve_config_credenciais.php';

if (file_exists($arquivoCredenciaisNovo)) {
    require $arquivoCredenciaisNovo;
    define('BANCO_CONFIGURADO', true);
} elseif (file_exists($arquivoCredenciaisAntigo)) {
    require $arquivoCredenciaisAntigo;
    define('BANCO_CONFIGURADO', true);
} else {
    define('BANCO_CONFIGURADO', false);
}

// Notices/warnings/deprecations nunca devem ser impressos direto na resposta (isso já
// corrompeu JSON de endpoint AJAX duas vezes neste projeto) — loga mas nunca exibe.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$pdo = null;

// Se o banco não está configurado ou a conexão falha (senha errada, banco
// fora do ar), $pdo fica null em vez de estourar um erro fatal aqui — só
// /painel_dev/login.php trata esse caso de verdade (mostra o assistente de
// configuração); as demais páginas continuam quebrando como sempre quebraram
// quando o banco está indisponível, isso não muda.
if (BANCO_CONFIGURADO) {
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $options[class_exists('Pdo\\Mysql') ? \Pdo\Mysql::ATTR_INIT_COMMAND : PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES utf8mb4";

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (Throwable $e) {
        error_log('conecta_bd: falha ao conectar ao banco de dados: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/includes/travamento.php';
verificarTravamentoManutencao($pdo);

// Raiz do projeto — todo caminho relativo salvo no banco (fotos de produto,
// logo da loja) é resolvido a partir daqui, não de __DIR__ do arquivo que
// está chamando (que muda de página pra página).
define('CAMINHO_RAIZ', __DIR__);

// Limite de fotos por produto — só regra da aplicação (produtos/editar.php,
// produtos/ajax/upload_foto.php), a coluna produto_fotos.ordem aceita até
// 255. Pra mudar o limite, só troca esse número.
define('MAX_FOTOS_PRODUTO', 6);

/**
 * Acrescenta "?v=<data de modificação do arquivo>" num caminho de foto —
 * mesmo truque já usado pro CSS/JS (includes/loja_header.php,
 * includes/admin_header.php). Sem isso, trocar a foto de um produto
 * (o arquivo final continua com o mesmo nome) não atualizava pra quem já
 * tinha a página aberta: o navegador seguia servindo a versão antiga do
 * cache porque a URL nunca mudava.
 */
function fotoComVersao(?string $caminhoRelativo): ?string
{
    if (!$caminhoRelativo) {
        return $caminhoRelativo;
    }
    $versao = @filemtime(CAMINHO_RAIZ . '/' . $caminhoRelativo) ?: time();
    return $caminhoRelativo . '?v=' . $versao;
}

/**
 * Converte um valor no formato brasileiro "1.234,56" (o que a máscara JS
 * de moeda/porcentagem produz nos campos .js-mascara-moeda/.js-mascara-
 * percentual) pro float 1234.56. Precisa tirar o "." de milhar ANTES de
 * trocar "," por "." de decimal — um simples str_replace(',', '.') sozinho
 * (usado no código antes desta função existir) quebra em qualquer valor
 * >= 1000: "1.234,56" viraria "1.234.56", e o cast (float) para no
 * primeiro ponto e lê só "1.234" (== 1.234, errado).
 */
function converterMoedaBrParaFloat(?string $valor): float
{
    $limpo = str_replace('.', '', $valor ?? '');
    $limpo = str_replace(',', '.', $limpo);
    return (float) $limpo;
}

/**
 * O sistema pode ser instalado em domínios diferentes por cliente (mesma
 * estrutura de pastas, domínio muda) — então toda URL absoluta que o código
 * precisa montar (webhook do Mercado Pago, redirect_uri do OAuth, back_urls
 * do checkout, links de compartilhamento/e-mail) usa isto em vez de um
 * domínio fixo no código.
 */
function dominioAtual(): string
{
    return $_SERVER['HTTP_HOST'] ?? 'localhost';
}

/**
 * E-mail "pagador" que o Mercado Pago exige pra gerar um Pix sem cliente
 * identificado -- só precisa ter formato válido, nunca recebe nada. Sai do
 * domínio onde o sistema está instalado (cada instalação tem o seu).
 */
function emailPagadorPadrao(): string
{
    $host = preg_replace('/^www\./', '', explode(':', dominioAtual())[0]);
    return 'pagamentos@' . $host;
}

function urlBaseAtual(): string
{
    // Em hospedagem atrás de proxy/balanceador que termina o HTTPS antes do
    // PHP, $_SERVER['HTTPS'] pode nunca chegar setado mesmo com o site sendo
    // servido em https — nesse caso quem carrega essa informação é o
    // cabeçalho X-Forwarded-Proto. Sem checar isso, uma redirect_uri/webhook
    // montada como "http://" quando o site real é "https://" faz o Mercado
    // Pago recusar a conexão por não bater com o que está cadastrado lá.
    $protocoloForwardeado = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $https = $protocoloForwardeado === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    return ($https ? 'https' : 'http') . '://' . dominioAtual();
}

/**
 * Sorteia um código de 3 dígitos (000-999) ainda não usado por nenhum
 * produto — funciona como o código de barras/etiqueta física da peça.
 * De propósito não é sequencial (001, 002...): sorteia e verifica se já
 * está em uso, repetindo até achar um livre, pra não dar pra adivinhar
 * o próximo código só de olhar o anterior.
 */
// Códigos que o sistema nunca pode sortear/atribuir a um produto — pedido
// explícito do lojista (ex: "666"), fora da lógica normal de duplicidade.
const CODIGOS_PRODUTO_BLOQUEADOS = ['666', '013', '012', '022'];

function gerarCodigoProdutoUnico(PDO $pdo): string
{
    for ($tentativas = 0; $tentativas < 300; $tentativas++) {
        $codigo = str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
        if (in_array($codigo, CODIGOS_PRODUTO_BLOQUEADOS, true)) {
            continue;
        }
        $existe = $pdo->prepare('SELECT 1 FROM produtos WHERE codigo = :c');
        $existe->execute([':c' => $codigo]);
        if (!$existe->fetch()) {
            return $codigo;
        }
    }
    throw new RuntimeException('Não foi possível gerar um código de produto único — os 1000 códigos possíveis estão todos em uso.');
}
