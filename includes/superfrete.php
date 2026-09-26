<?php
require_once __DIR__ . '/loja.php';

/**
 * Integração SuperFrete: cotação de frete na loja ("consulta") e geração de
 * etiquetas pelo lojista ("etiquetas", que inclui tudo da consulta). Só
 * funciona se o dev ligou o recurso no painel_dev — com superfrete_modo =
 * 'desativado' nada do que está aqui aparece nem é chamado.
 */

const SUPERFRETE_SERVICOS = [
    1 => 'PAC (Correios)',
    2 => 'SEDEX (Correios)',
    3 => 'Jadlog',
    17 => 'Mini Envios (Correios)',
    31 => 'Loggi Econômico',
];
const SUPERFRETE_SERVICOS_PADRAO = '1,2';

// tamanho da caixa (produtos.caixa_tamanho) => nome + dimensões em cm
const SUPERFRETE_CAIXAS = [
    1 => ['nome' => 'Envelope', 'altura' => 2, 'largura' => 16, 'comprimento' => 24],
    2 => ['nome' => 'Pequena', 'altura' => 10, 'largura' => 15, 'comprimento' => 25],
    3 => ['nome' => 'Média', 'altura' => 15, 'largura' => 25, 'comprimento' => 35],
    4 => ['nome' => 'Grande', 'altura' => 30, 'largura' => 40, 'comprimento' => 50],
    5 => ['nome' => 'Extra grande', 'altura' => 40, 'largura' => 50, 'comprimento' => 70],
];
const SUPERFRETE_PESO_PADRAO_GRAMAS = 500;
const SUPERFRETE_PESO_MINIMO_KG = 0.03;
const SUPERFRETE_SEGURO_MINIMO = 26.0;
const SUPERFRETE_SOBRETAXA_MULTIPLOS = 5.0;

function superfreteModo(PDO $pdo): string
{
    $modo = $pdo->query('SELECT superfrete_modo FROM config_dev WHERE id_config = 1')->fetchColumn();
    return in_array($modo, ['consulta', 'etiquetas'], true) ? $modo : 'desativado';
}

function superfreteAtivo(PDO $pdo): bool
{
    return superfreteModo($pdo) !== 'desativado';
}

function superfreteEtiquetasAtivas(PDO $pdo): bool
{
    return superfreteModo($pdo) === 'etiquetas';
}

function superfreteConfigLojista(PDO $pdo): array
{
    return $pdo->query(
        'SELECT superfrete_token, superfrete_cep_origem, superfrete_servicos, superfrete_seguro FROM config_loja WHERE id_config = 1'
    )->fetch() ?: [];
}

/** IDs de serviço ativos pro lojista (já validados contra a lista permitida). */
function superfreteServicosAtivos(PDO $pdo): array
{
    $csv = superfreteConfigLojista($pdo)['superfrete_servicos'] ?? SUPERFRETE_SERVICOS_PADRAO;
    $ids = array_values(array_filter(
        array_map('intval', explode(',', (string) $csv)),
        fn($id) => isset(SUPERFRETE_SERVICOS[$id])
    ));
    return $ids ?: array_map('intval', explode(',', SUPERFRETE_SERVICOS_PADRAO));
}

function superfreteAmbienteBase(PDO $pdo): string
{
    $ambiente = $pdo->query('SELECT superfrete_ambiente FROM config_dev WHERE id_config = 1')->fetchColumn();
    return $ambiente === 'sandbox' ? 'https://sandbox.superfrete.com' : 'https://api.superfrete.com';
}

/**
 * Token usado nas cotações: o do lojista quando ele já cadastrou o dele,
 * senão o token geral do dev (é o que permite o modo "consulta" funcionar
 * sem o lojista ter conta na SuperFrete). Etiquetas SEMPRE usam o do lojista.
 */
function superfreteTokenParaCotar(PDO $pdo): ?string
{
    $token = trim((string) (superfreteConfigLojista($pdo)['superfrete_token'] ?? ''));
    if ($token !== '') {
        return $token;
    }
    $dev = trim((string) $pdo->query('SELECT superfrete_token_dev FROM config_dev WHERE id_config = 1')->fetchColumn());
    return $dev !== '' ? $dev : null;
}

function superfreteChamar(PDO $pdo, string $metodo, string $caminho, ?array $payload, string $token): array
{
    $ch = curl_init(superfreteAmbienteBase($pdo) . $caminho);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'User-Agent: CoderNex-Loja/1.0',
        ],
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload ?? new stdClass());
    }
    curl_setopt_array($ch, $opts);
    $resposta = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        throw new Exception('Erro de conexão com a SuperFrete: ' . $erro);
    }
    return ['http_code' => $httpCode, 'dados' => json_decode($resposta, true)];
}

function superfreteSomenteDigitos(?string $valor): string
{
    return preg_replace('/\D/', '', (string) $valor);
}

/**
 * Volume + lista de produtos + valor declarado da venda, a partir do peso e
 * da caixa cadastrados em cada produto (ambos obrigatórios). A caixa do envio
 * é a MAIOR entre as dos itens; com mais de uma peça sobe um nível na tabela
 * (mais folga pra embalar tudo junto) e o peso é a soma de tudo. 'sobretaxa'
 * é a margem interna que o lojista embute no frete quando o pedido tem mais
 * de uma peça — o cliente vê só o valor final, nunca essa parte separada.
 */
function superfreteVolumeDaVenda(PDO $pdo, int $id_venda): array
{
    $stmt = $pdo->prepare(
        "SELECT iv.nome_produto, iv.quantidade, iv.preco_unit, p.peso_gramas, p.caixa_tamanho
         FROM itens_venda iv
         LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
         LEFT JOIN produtos p ON p.id_produto = pv.id_produto
         WHERE iv.id_venda = :id AND NOT (iv.id_produto_variacao IS NULL AND iv.nome_produto = 'Entrega')"
    );
    $stmt->execute([':id' => $id_venda]);

    $caixa = 1;
    $pesoGramas = 0;
    $pecas = 0;
    $valorDeclarado = 0.0;
    $produtos = [];
    foreach ($stmt->fetchAll() as $item) {
        $qtd = max(1, (int) $item['quantidade']);
        if ((int) $item['peso_gramas'] <= 0 || !isset(SUPERFRETE_CAIXAS[(int) $item['caixa_tamanho']])) {
            throw new Exception('O produto "' . $item['nome_produto'] . '" está sem peso/caixa cadastrados — frete indisponível. Fale com a loja.');
        }
        $caixa = max($caixa, (int) $item['caixa_tamanho']);
        $pesoGramas += (int) $item['peso_gramas'] * $qtd;
        $pecas += $qtd;
        $valorDeclarado += (float) $item['preco_unit'] * $qtd;
        $produtos[] = [
            'name' => $item['nome_produto'],
            'quantity' => $qtd,
            'unitary_value' => round((float) $item['preco_unit'], 2),
        ];
    }
    if ($pecas === 0) {
        throw new Exception('Carrinho vazio.');
    }
    if ($pecas > 1) {
        $caixa = min($caixa + 1, max(array_keys(SUPERFRETE_CAIXAS)));
    }

    $dim = SUPERFRETE_CAIXAS[$caixa];
    return [
        'volume' => [
            'height' => $dim['altura'],
            'width' => $dim['largura'],
            'length' => $dim['comprimento'],
            'weight' => max(SUPERFRETE_PESO_MINIMO_KG, round($pesoGramas / 1000, 3)),
        ],
        'produtos' => $produtos,
        'valor_declarado' => round($valorDeclarado, 2),
        'sobretaxa' => $pecas > 1 ? SUPERFRETE_SOBRETAXA_MULTIPLOS : 0.0,
        // muda quando o carrinho muda — invalida cotação guardada na sessão
        'assinatura' => md5(json_encode([$produtos, $caixa, $pesoGramas])),
    ];
}

/**
 * Cota o frete da venda até $cepDestino. Retorna lista normalizada:
 * [['id'=>int,'nome'=>string,'valor'=>float,'prazo'=>int|null], ...] ordenada
 * do mais barato pro mais caro. Lança Exception com mensagem amigável.
 */
function superfreteCotar(PDO $pdo, int $id_venda, string $cepDestino): array
{
    $cepDestino = superfreteSomenteDigitos($cepDestino);
    if (strlen($cepDestino) !== 8) {
        throw new Exception('Informe um CEP válido.');
    }
    $cfg = superfreteConfigLojista($pdo);
    $cepOrigem = superfreteSomenteDigitos($cfg['superfrete_cep_origem'] ?? '');
    if (strlen($cepOrigem) !== 8) {
        throw new Exception('A loja ainda não configurou o CEP de origem do envio.');
    }
    $token = superfreteTokenParaCotar($pdo);
    if (!$token) {
        throw new Exception('Cálculo de frete indisponível no momento.');
    }

    $dados = superfreteVolumeDaVenda($pdo, $id_venda);
    $usaSeguro = !empty($cfg['superfrete_seguro']);

    $payload = [
        'from' => ['postal_code' => $cepOrigem],
        'to' => ['postal_code' => $cepDestino],
        'services' => implode(',', superfreteServicosAtivos($pdo)),
        'options' => [
            'own_hand' => false,
            'receipt' => false,
            'insurance_value' => $usaSeguro ? max(SUPERFRETE_SEGURO_MINIMO, $dados['valor_declarado']) : 0,
            'use_insurance_value' => $usaSeguro,
        ],
        'package' => $dados['volume'],
    ];

    $resp = superfreteChamar($pdo, 'POST', '/api/v0/calculator', $payload, $token);
    if ($resp['http_code'] >= 400 || !is_array($resp['dados'])) {
        error_log('SuperFrete calculator HTTP ' . $resp['http_code']);
        throw new Exception('Não foi possível calcular o frete agora. Tente novamente.');
    }

    $ativos = superfreteServicosAtivos($pdo);
    $opcoes = [];
    foreach ($resp['dados'] as $frete) {
        if (!is_array($frete) || !empty($frete['has_error']) || !empty($frete['error']) || !isset($frete['price'], $frete['id'])) {
            continue;
        }
        $id = (int) $frete['id'];
        if (!in_array($id, $ativos, true)) {
            continue;
        }
        $opcoes[] = [
            'id' => $id,
            'nome' => SUPERFRETE_SERVICOS[$id] ?? (string) ($frete['name'] ?? 'Frete'),
            'valor' => round((float) $frete['price'] + $dados['sobretaxa'], 2),
            'prazo' => isset($frete['delivery_time']) ? (int) $frete['delivery_time'] : null,
        ];
    }
    usort($opcoes, fn($a, $b) => $a['valor'] <=> $b['valor']);

    // Preços ficam só no servidor (sessão): o navegador manda apenas o ID do
    // serviço escolhido, então mexer na tela não muda o valor cobrado.
    $_SESSION['frete_cotacao'] = [
        'id_venda' => $id_venda,
        'cep' => $cepDestino,
        'assinatura' => $dados['assinatura'],
        'opcoes' => array_column($opcoes, null, 'id'),
    ];
    return $opcoes;
}

/** Linha de vendas_envio da venda, ou null. */
function superfreteEnvioDaVenda(PDO $pdo, int $id_venda): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM vendas_envio WHERE id_venda = :id');
    $stmt->execute([':id' => $id_venda]);
    return $stmt->fetch() ?: null;
}

/**
 * Grava (ou atualiza) os dados de envio da compra com o valor já cotado. NÃO
 * usa dados do cadastro do cliente — vêm do formulário do checkout.
 */
function superfreteSalvarEnvio(PDO $pdo, int $id_venda, array $servico, array $destino): void
{
    $pdo->prepare(
        'INSERT INTO vendas_envio
            (id_venda, servico_id, servico_nome, prazo_dias, valor_frete, destino_nome, destino_documento, destino_telefone, destino_email,
             destino_cep, destino_endereco, destino_numero, destino_complemento, destino_bairro, destino_cidade, destino_uf, volume_json)
         VALUES (:iv, :sid, :sn, :prazo, :valor, :nome, :doc, :tel, :email, :cep, :end, :num, :comp, :bairro, :cidade, :uf, :vol)
         ON DUPLICATE KEY UPDATE servico_id = VALUES(servico_id), servico_nome = VALUES(servico_nome), prazo_dias = VALUES(prazo_dias),
            valor_frete = VALUES(valor_frete), destino_nome = VALUES(destino_nome), destino_documento = VALUES(destino_documento),
            destino_telefone = VALUES(destino_telefone), destino_email = VALUES(destino_email), destino_cep = VALUES(destino_cep),
            destino_endereco = VALUES(destino_endereco), destino_numero = VALUES(destino_numero), destino_complemento = VALUES(destino_complemento),
            destino_bairro = VALUES(destino_bairro), destino_cidade = VALUES(destino_cidade), destino_uf = VALUES(destino_uf), volume_json = VALUES(volume_json)'
    )->execute([
        ':iv' => $id_venda,
        ':sid' => $servico['id'],
        ':sn' => $servico['nome'],
        ':prazo' => $servico['prazo'],
        ':valor' => $servico['valor'],
        ':nome' => $destino['nome'],
        ':doc' => $destino['documento'],
        ':tel' => $destino['telefone'] ?: null,
        ':email' => $destino['email'] ?: null,
        ':cep' => $destino['cep'],
        ':end' => $destino['endereco'],
        ':num' => $destino['numero'],
        ':comp' => $destino['complemento'] ?: null,
        ':bairro' => $destino['bairro'],
        ':cidade' => $destino['cidade'],
        ':uf' => $destino['uf'],
        ':vol' => json_encode(superfreteVolumeDaVenda($pdo, $id_venda)['volume']),
    ]);
}

/**
 * Valida e normaliza os campos de endereço vindos do formulário do checkout.
 * Retorna [destino, erro] — erro é string amigável ou null.
 */
function superfreteValidarDestino(array $post): array
{
    $d = [
        'nome' => trim($post['envio_nome'] ?? ''),
        'documento' => superfreteSomenteDigitos($post['envio_documento'] ?? ''),
        'telefone' => superfreteSomenteDigitos($post['envio_telefone'] ?? ''),
        'email' => trim($post['envio_email'] ?? ''),
        'cep' => superfreteSomenteDigitos($post['envio_cep'] ?? ''),
        'endereco' => trim($post['envio_endereco'] ?? ''),
        'numero' => trim($post['envio_numero'] ?? ''),
        'complemento' => trim($post['envio_complemento'] ?? ''),
        'bairro' => trim($post['envio_bairro'] ?? ''),
        'cidade' => trim($post['envio_cidade'] ?? ''),
        'uf' => strtoupper(trim($post['envio_uf'] ?? '')),
    ];
    if ($d['nome'] === '') { return [$d, 'Informe o nome de quem vai receber.']; }
    if (!in_array(strlen($d['documento']), [11, 14], true)) { return [$d, 'Informe um CPF (ou CNPJ) válido de quem vai receber.']; }
    if (strlen($d['cep']) !== 8) { return [$d, 'Informe um CEP válido.']; }
    if ($d['endereco'] === '' || $d['numero'] === '' || $d['bairro'] === '' || $d['cidade'] === '') { return [$d, 'Preencha rua, número, bairro e cidade.']; }
    if (!preg_match('/^[A-Z]{2}$/', $d['uf'])) { return [$d, 'Informe o estado (UF).']; }
    if ($d['telefone'] !== '' && !in_array(strlen($d['telefone']), [10, 11], true)) { return [$d, 'Telefone inválido — use DDD + número.']; }
    if ($d['email'] !== '' && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) { return [$d, 'E-mail inválido.']; }
    return [$d, null];
}

/**
 * Gera a etiqueta da venda: só aqui a API "de verdade" é usada (carrinho ->
 * pagamento com saldo da carteira SuperFrete -> link de impressão). O
 * remetente é o endereço padrão da conta SuperFrete do lojista. Idempotente:
 * se já tem etiqueta, só busca de novo o link de impressão.
 */
function superfreteGerarEtiqueta(PDO $pdo, int $id_venda): array
{
    if (!superfreteEtiquetasAtivas($pdo)) {
        return ['success' => false, 'message' => 'Geração de etiquetas não está ativa.'];
    }
    $token = trim((string) (superfreteConfigLojista($pdo)['superfrete_token'] ?? ''));
    if ($token === '') {
        return ['success' => false, 'message' => 'Cadastre o token da SuperFrete em Configurações → SuperFrete.'];
    }

    $stmt = $pdo->prepare("SELECT status FROM vendas WHERE id_venda = :id AND origem = 'loja'");
    $stmt->execute([':id' => $id_venda]);
    $status = $stmt->fetchColumn();
    if ($status !== 'Pago') {
        return ['success' => false, 'message' => 'Só é possível gerar etiqueta de pedido pago.'];
    }
    $envio = superfreteEnvioDaVenda($pdo, $id_venda);
    if (!$envio || !$envio['servico_id']) {
        return ['success' => false, 'message' => 'Este pedido não foi feito com frete SuperFrete.'];
    }
    if (empty($envio['nota_chave']) || empty($envio['nota_numero'])) {
        return ['success' => false, 'message' => 'Informe o número e a chave da nota fiscal do pedido antes de gerar a etiqueta.'];
    }

    try {
        $orderId = $envio['superfrete_order_id'];

        if (!$orderId) {
            $enderecos = superfreteChamar($pdo, 'GET', '/api/v0/user/addresses', null, $token);
            if ($enderecos['http_code'] >= 400) {
                return ['success' => false, 'message' => 'Token da SuperFrete inválido ou sem permissão.'];
            }
            $lista = $enderecos['dados']['data'] ?? $enderecos['dados'] ?? [];
            $remetente = null;
            foreach ((array) $lista as $e) {
                if (is_array($e) && !empty($e['is_default'])) { $remetente = $e; break; }
            }
            $remetente = $remetente ?: (is_array($lista) ? (reset($lista) ?: null) : null);
            if (!$remetente) {
                return ['success' => false, 'message' => 'Cadastre um endereço de remetente na sua conta SuperFrete.'];
            }

            $dados = superfreteVolumeDaVenda($pdo, $id_venda);
            $cfg = superfreteConfigLojista($pdo);
            $usaSeguro = !empty($cfg['superfrete_seguro']);

            $from = [
                'name' => $remetente['name'] ?? '',
                'address' => $remetente['address'] ?? '',
                'number' => (string) ($remetente['number'] ?? ''),
                'district' => $remetente['district'] ?? '',
                'city' => $remetente['city'] ?? '',
                'state_abbr' => $remetente['state_abbr'] ?? '',
                'postal_code' => superfreteSomenteDigitos($remetente['postal_code'] ?? ''),
            ];
            if (!empty($remetente['complement'])) { $from['complement'] = $remetente['complement']; }
            if (!empty($remetente['document'])) { $from['document'] = superfreteSomenteDigitos($remetente['document']); }

            $to = [
                'name' => $envio['destino_nome'],
                'address' => $envio['destino_endereco'],
                'number' => $envio['destino_numero'],
                'district' => $envio['destino_bairro'],
                'city' => $envio['destino_cidade'],
                'state_abbr' => $envio['destino_uf'],
                'postal_code' => $envio['destino_cep'],
                'document' => $envio['destino_documento'],
            ];
            if (!empty($envio['destino_complemento'])) { $to['complement'] = $envio['destino_complemento']; }
            if (!empty($envio['destino_email'])) { $to['email'] = $envio['destino_email']; }
            if (!empty($envio['destino_telefone'])) { $to['phone'] = $envio['destino_telefone']; }

            $carrinho = superfreteChamar($pdo, 'POST', '/api/v0/cart', [
                'from' => $from,
                'to' => $to,
                'service' => (int) $envio['servico_id'],
                'volumes' => $dados['volume'],
                'products' => $dados['produtos'],
                'options' => [
                    'insurance_value' => $usaSeguro ? max(SUPERFRETE_SEGURO_MINIMO, $dados['valor_declarado']) : 0,
                    'receipt' => false,
                    'own_hand' => false,
                    // Nota fiscal obrigatória: a chave vem de vendas_envio
                    // (digitada hoje; preenchida pelo módulo de NF quando existir).
                    'non_commercial' => false,
                    'invoice' => ['number' => $envio['nota_numero'], 'key' => $envio['nota_chave']],
                ],
                'platform' => 'CoderNex',
            ], $token);

            $orderId = $carrinho['dados']['id'] ?? null;
            if ($carrinho['http_code'] >= 400 || !$orderId) {
                $msg = $carrinho['dados']['message'] ?? 'resposta inesperada';
                error_log('SuperFrete cart HTTP ' . $carrinho['http_code'] . ' venda ' . $id_venda);
                return ['success' => false, 'message' => 'A SuperFrete recusou o envio: ' . (is_string($msg) ? $msg : 'confira os dados do destinatário e do remetente.')];
            }
            $pdo->prepare('UPDATE vendas_envio SET superfrete_order_id = :o, superfrete_status = :s WHERE id_venda = :iv')
                ->execute([':o' => $orderId, ':s' => $carrinho['dados']['status'] ?? 'pending', ':iv' => $id_venda]);

            $checkout = superfreteChamar($pdo, 'POST', '/api/v0/checkout', ['orders' => [$orderId]], $token);
            if ($checkout['http_code'] >= 400) {
                $msg = $checkout['dados']['message'] ?? 'saldo insuficiente na carteira SuperFrete?';
                return ['success' => false, 'message' => 'Envio criado na SuperFrete, mas o pagamento da etiqueta falhou: ' . (is_string($msg) ? $msg : 'confira o saldo da sua carteira') . '. Tente de novo depois de recarregar.'];
            }
            $pedidoPago = $checkout['dados']['purchase']['orders'][0] ?? [];
            $pdo->prepare(
                'UPDATE vendas_envio SET superfrete_status = :s, superfrete_rastreio = :t, superfrete_valor_etiqueta = :v WHERE id_venda = :iv'
            )->execute([
                ':s' => 'released',
                ':t' => $pedidoPago['tracking'] ?? null,
                ':v' => isset($pedidoPago['price']) ? (float) $pedidoPago['price'] : null,
                ':iv' => $id_venda,
            ]);
        }

        $impressao = superfreteChamar($pdo, 'POST', '/api/v0/tag/print', ['orders' => [$orderId]], $token);
        $url = $impressao['dados']['url'] ?? null;
        if ($impressao['http_code'] >= 400 || !$url) {
            return ['success' => false, 'message' => 'Pagamento feito, mas a SuperFrete ainda não liberou o PDF. Tente de novo em instantes.'];
        }

        // Rastreio pode só existir depois — busca o estado atual do envio.
        $info = superfreteChamar($pdo, 'GET', '/api/v0/order/info/' . rawurlencode((string) $orderId), null, $token);
        $rastreio = $info['dados']['tracking'] ?? null;

        $pdo->prepare(
            'UPDATE vendas_envio SET superfrete_etiqueta_url = :u, superfrete_status = COALESCE(:s, superfrete_status),
                    superfrete_rastreio = COALESCE(:t, superfrete_rastreio), etiqueta_gerada_em = COALESCE(etiqueta_gerada_em, NOW())
             WHERE id_venda = :iv'
        )->execute([':u' => $url, ':s' => $info['dados']['status'] ?? null, ':t' => $rastreio, ':iv' => $id_venda]);

        return ['success' => true, 'message' => 'Etiqueta gerada.', 'url' => $url, 'rastreio' => $rastreio];
    } catch (Throwable $e) {
        error_log('superfreteGerarEtiqueta: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao conectar com a SuperFrete. Tente novamente.'];
    }
}


/**
 * Usado por gerar_checkout.php e finalizar_credito.php quando a forma de
 * entrega escolhida é a SuperFrete: valida endereço + serviço escolhido
 * contra a cotação guardada na sessão, grava os dados de envio da compra e
 * devolve a "forma de entrega" com o custo (já com a sobretaxa interna) pra
 * definirEntregaDaVenda(). Retorna [entrega|null, erro|null].
 */
function superfreteAplicarNoCheckout(PDO $pdo, int $id_venda, array $entregaBase, array $post): array
{
    [$destino, $erro] = superfreteValidarDestino($post);
    if ($erro) {
        return [null, $erro];
    }

    try {
        $assinatura = superfreteVolumeDaVenda($pdo, $id_venda)['assinatura'];
    } catch (Throwable $e) {
        return [null, $e->getMessage()];
    }

    $cot = $_SESSION['frete_cotacao'] ?? null;
    $servicoId = (int) ($post['frete_servico'] ?? 0);
    if (!$cot || (int) $cot['id_venda'] !== $id_venda || $cot['assinatura'] !== $assinatura
        || $cot['cep'] !== $destino['cep'] || !isset($cot['opcoes'][$servicoId])) {
        return [null, 'Calcule o frete de novo e escolha um serviço — o carrinho ou o CEP mudou.'];
    }

    $servico = $cot['opcoes'][$servicoId];
    superfreteSalvarEnvio($pdo, $id_venda, $servico, $destino);

    $entregaBase['nome'] = 'SuperFrete — ' . $servico['nome'];
    $entregaBase['custo'] = $servico['valor'];
    return [$entregaBase, null];
}

/**
 * Peso (g) e caixa do produto — obrigatórios só enquanto a SuperFrete está
 * ativa. Retorna [peso|null, caixa|null, erro|null].
 */
function superfreteValidarProduto(PDO $pdo, array $post): array
{
    $peso = (int) preg_replace('/\D/', '', (string) ($post['peso_gramas'] ?? ''));
    $caixa = (int) ($post['caixa_tamanho'] ?? 0);
    $caixa = isset(SUPERFRETE_CAIXAS[$caixa]) ? $caixa : null;

    if (!superfreteAtivo($pdo)) {
        return [$peso > 0 ? $peso : null, $caixa, null];
    }
    if ($peso <= 0 || $caixa === null) {
        return [null, null, 'Informe o peso (em gramas) e o tamanho da caixa do produto — são obrigatórios para calcular o frete.'];
    }
    return [$peso, $caixa, null];
}

/** Campos de peso/caixa do formulário de produto (só aparecem com a SuperFrete ativa). */
function superfreteCamposProduto(PDO $pdo, ?int $peso, ?int $caixa): void
{
    if (!superfreteAtivo($pdo)) {
        return;
    }
    echo '<label>Peso do produto embalado (gramas)<input type="text" name="peso_gramas" inputmode="numeric" value="' . ($peso ? (int) $peso : '') . '" placeholder="Ex: 350" required></label>';
    echo '<label>Caixa usada no envio<select name="caixa_tamanho" required><option value="">Selecione</option>';
    foreach (SUPERFRETE_CAIXAS as $id => $c) {
        echo '<option value="' . $id . '"' . ((int) $caixa === $id ? ' selected' : '') . '>'
            . htmlspecialchars($c['nome'] . ' (' . $c['comprimento'] . '×' . $c['largura'] . '×' . $c['altura'] . ' cm)') . '</option>';
    }
    echo '</select></label>';
}
