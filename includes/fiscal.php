<?php
require_once __DIR__ . '/brasilnfe.php';

/**
 * Nota fiscal de produto (Brasil NFe): configuração do lojista, regras de
 * emissão, livro de notas, conferência e cancelamento.
 *
 * - Venda do PDV (vendas.origem = 'pdv')  -> NFC-e (cupom)
 * - Venda online (vendas.origem = 'loja') -> NF-e (DANFE em A4), com os dados do destinatário
 * Função nativa: o lojista liga/desliga em Configurações → Nota fiscal. Desligada,
 * nada fiscal aparece em lugar nenhum. Sem dado fiscal completo no produto, a nota não sai.
 */

/** CFOPs aceitos em NFC-e (venda a consumidor final, dentro do estado). */
const FISCAL_CFOP_NFCE = ['5101', '5102', '5103', '5104', '5115', '5405', '5656', '5667', '5933'];
const FISCAL_PRAZO_CANCELAR_NFCE_MIN = 30;
const FISCAL_CONFERIR_APOS_MIN = 1;
const FISCAL_DESISTIR_APOS_MIN = 10;
const FISCAL_ORIGENS = [0 => '0 · Nacional', 1 => '1 · Estrangeira (importação direta)', 2 => '2 · Estrangeira (mercado interno)', 3 => '3 · Nacional (importação 40% a 70%)', 4 => '4 · Nacional (processos básicos)', 5 => '5 · Nacional (importação até 40%)', 6 => '6 · Estrangeira sem similar (CAMEX)', 7 => '7 · Estrangeira sem similar, mercado interno', 8 => '8 · Nacional (importação acima de 70%)'];
const FISCAL_UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
const FISCAL_TEXTO_RESPONSABILIDADE = 'Declaro que os dados fiscais (NCM, CFOP, grupo tributário, origem e demais informações) são definidos por mim e pelo meu contador, e que a conferência e a conformidade fiscal das notas emitidas são de minha responsabilidade. O sistema apenas envia as informações à Brasil NFe e não presta consultoria fiscal nem contábil.';

class FiscalErro extends RuntimeException
{
}

// ---- Configuração -------------------------------------------------------------------------

/** @return array<string,mixed> config do lojista (com padrões se a tabela ainda não existe) */
function fiscalConfig(PDO $pdo, bool $recarregar = false): array
{
    static $cache = null;
    if ($cache !== null && !$recarregar) {
        return $cache;
    }
    $padrao = [
        'ativo' => 0, 'token' => null, 'ambiente' => 'homologacao', 'conectado_em' => null, 'emite_nfce' => 1, 'emite_nfe' => 1,
        'aceite_em' => null, 'aceite_por' => null, 'uf_loja' => null, 'cfop_padrao' => '5102', 'origem_padrao' => 0,
        'unidade_padrao' => 'UN', 'cod_tributacao_padrao' => null, 'tabela_ok' => false,
    ];
    try {
        $row = $pdo->query('SELECT * FROM fiscal_config WHERE id_config = 1')->fetch();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return $cache = $padrao;
        }
        throw $e;
    }

    return $cache = array_merge($padrao, $row ?: [], ['tabela_ok' => $row !== false]);
}

/** O lojista ligou a nota fiscal? (controla se a função aparece no sistema) */
function fiscalAtivo(PDO $pdo): bool
{
    return (int) fiscalConfig($pdo)['ativo'] === 1;
}

/** Ligada + token conectado + responsabilidade aceita + ao menos um tipo de nota habilitado. */
function fiscalPronto(PDO $pdo): bool
{
    $c = fiscalConfig($pdo);

    return (int) $c['ativo'] === 1 && $c['conectado_em'] !== null && !empty($c['token']) && $c['aceite_em'] !== null
        && ((int) $c['emite_nfce'] === 1 || (int) $c['emite_nfe'] === 1);
}

function fiscalGateway(PDO $pdo, ?string $token = null, ?string $ambiente = null): BrasilNfeGateway
{
    $c = fiscalConfig($pdo);

    return new BrasilNfeGateway($token ?? (string) $c['token'], $ambiente ?? (string) $c['ambiente']);
}

/** Salva os padrões e liga/desliga. Lança FiscalErro(mensagem) se algo estiver inválido. */
function fiscalSalvarConfig(PDO $pdo, array $d, int $idUsuario): void
{
    $uf = strtoupper(trim((string) ($d['uf_loja'] ?? '')));
    if ($uf !== '' && !in_array($uf, FISCAL_UFS, true)) {
        throw new FiscalErro('Estado (UF) inválido.');
    }
    $cfop = trim((string) ($d['cfop_padrao'] ?? '5102'));
    if (!preg_match('/^5\d{3}$/', $cfop)) {
        throw new FiscalErro('O CFOP padrão precisa ter 4 números e começar com 5 (venda dentro do estado), ex.: 5102.');
    }
    $origem = (string) ($d['origem_padrao'] ?? '0');
    if (!ctype_digit($origem) || !isset(FISCAL_ORIGENS[(int) $origem])) {
        throw new FiscalErro('Origem padrão inválida.');
    }
    $unidade = strtoupper(trim((string) ($d['unidade_padrao'] ?? 'UN')));
    if ($unidade === '' || mb_strlen($unidade) > 6) {
        throw new FiscalErro('A unidade padrão tem de 1 a 6 letras (ex.: UN).');
    }
    $codTrib = trim((string) ($d['cod_tributacao_padrao'] ?? ''));
    if (mb_strlen($codTrib) > 40) {
        throw new FiscalErro('O grupo tributário padrão tem no máximo 40 caracteres.');
    }
    $ativo = !empty($d['ativo']) ? 1 : 0;
    $aceitou = !empty($d['aceite']);
    $atual = fiscalConfig($pdo);
    if ($ativo && $atual['aceite_em'] === null && !$aceitou) {
        throw new FiscalErro('Para ligar a nota fiscal, leia e aceite a declaração de responsabilidade.');
    }

    $pdo->prepare(
        'UPDATE fiscal_config SET ativo = :a, emite_nfce = :nc, emite_nfe = :ne, uf_loja = :uf, cfop_padrao = :cfop, origem_padrao = :o, unidade_padrao = :u,
                cod_tributacao_padrao = :ct, aceite_em = COALESCE(aceite_em, IF(:aceitou = 1, NOW(), NULL)), aceite_por = COALESCE(aceite_por, IF(:aceitou2 = 1, :usr, NULL))
         WHERE id_config = 1'
    )->execute([
        ':a' => $ativo, ':nc' => empty($d['emite_nfce']) ? 0 : 1, ':ne' => empty($d['emite_nfe']) ? 0 : 1, ':uf' => $uf ?: null,
        ':cfop' => $cfop, ':o' => (int) $origem, ':u' => $unidade, ':ct' => $codTrib ?: null,
        ':aceitou' => $aceitou ? 1 : 0, ':aceitou2' => $aceitou ? 1 : 0, ':usr' => $idUsuario,
    ]);
}

/** Valida o token na Brasil NFe e, se aceito, guarda. */
function fiscalConectar(PDO $pdo, string $token, string $ambiente): array
{
    $token = trim($token);
    if (strlen($token) < 8 || strlen($token) > 300 || preg_match('/\s/', $token)) {
        throw new FiscalErro('Cole o token completo da sua empresa na Brasil NFe (sem espaços).');
    }
    if (!in_array($ambiente, ['homologacao', 'producao'], true)) {
        throw new FiscalErro('Escolha o ambiente.');
    }
    $r = fiscalGateway($pdo, $token, $ambiente)->testarConexao();
    if (!$r['ok']) {
        throw new FiscalErro($r['mensagem']);
    }
    $pdo->prepare('UPDATE fiscal_config SET token = :t, ambiente = :a, conectado_em = NOW() WHERE id_config = 1')->execute([':t' => $token, ':a' => $ambiente]);

    return $r;
}

function fiscalDesconectar(PDO $pdo): void
{
    $pdo->exec('UPDATE fiscal_config SET token = NULL, conectado_em = NULL WHERE id_config = 1');
}

// ---- Documentos ---------------------------------------------------------------------------

/** @return ?array{numero:string,tipo:string} CPF ou CNPJ válido (só dígitos), ou null */
function fiscalIdentificarDocumento(string $texto): ?array
{
    $n = preg_replace('/\D/', '', $texto);
    if (strlen($n) === 11 && !preg_match('/^(\d)\1{10}$/', $n)) {
        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $n[$i] * ($t + 1 - $i);
            }
            if (((10 * $soma) % 11) % 10 !== (int) $n[$t]) {
                return null;
            }
        }

        return ['numero' => $n, 'tipo' => 'CPF'];
    }
    if (strlen($n) === 14 && !preg_match('/^(\d)\1{13}$/', $n)) {
        foreach ([[5, 12], [6, 13]] as [$inicio, $pos]) {
            $soma = 0;
            $peso = $inicio;
            for ($i = 0; $i < $pos; $i++) {
                $soma += (int) $n[$i] * $peso;
                $peso = $peso === 2 ? 9 : $peso - 1;
            }
            $dv = $soma % 11 < 2 ? 0 : 11 - $soma % 11;
            if ($dv !== (int) $n[$pos]) {
                return null;
            }
        }

        return ['numero' => $n, 'tipo' => 'CNPJ'];
    }

    return null;
}

// ---- Dados fiscais do produto ---------------------------------------------------------------

/** @return string[] o que falta no cadastro do produto para sair em nota */
function fiscalProdutoFaltando(array $p, array $cfg): array
{
    $f = [];
    $ncm = (string) ($p['fiscal_ncm'] ?? '');
    if (!preg_match('/^\d{8}$/', $ncm) || $ncm === '00000000') {
        $f[] = 'NCM (8 números)';
    }
    if (trim((string) ($p['fiscal_cod_tributacao'] ?? '')) === '' && trim((string) ($cfg['cod_tributacao_padrao'] ?? '')) === '') {
        $f[] = 'grupo tributário';
    }

    return $f;
}

/** Lê os campos fiscais do formulário de produto. @return array{0: array<string,mixed>, 1: ?string} [dados, erro] */
function fiscalLerDadosProduto(array $d): array
{
    $ncm = preg_replace('/\D/', '', (string) ($d['fiscal_ncm'] ?? ''));
    if ($ncm !== '' && (strlen($ncm) !== 8 || $ncm === '00000000')) {
        return [[], 'O NCM tem 8 números.'];
    }
    $cfop = trim((string) ($d['fiscal_cfop'] ?? ''));
    if ($cfop !== '' && !preg_match('/^5\d{3}$/', $cfop)) {
        return [[], 'O CFOP do produto precisa ter 4 números e começar com 5 (ex.: 5102).'];
    }
    $origem = trim((string) ($d['fiscal_origem'] ?? ''));
    if ($origem !== '' && (!ctype_digit($origem) || !isset(FISCAL_ORIGENS[(int) $origem]))) {
        return [[], 'Origem do produto inválida.'];
    }
    $unidade = strtoupper(trim((string) ($d['fiscal_unidade'] ?? '')));
    if (mb_strlen($unidade) > 6) {
        return [[], 'A unidade do produto tem até 6 letras.'];
    }
    $cod = trim((string) ($d['fiscal_cod_tributacao'] ?? ''));
    if (mb_strlen($cod) > 40) {
        return [[], 'O grupo tributário tem no máximo 40 caracteres.'];
    }

    return [[
        'fiscal_ncm' => $ncm ?: null, 'fiscal_cfop' => $cfop ?: null, 'fiscal_origem' => $origem === '' ? null : (int) $origem,
        'fiscal_unidade' => $unidade ?: null, 'fiscal_cod_tributacao' => $cod ?: null,
    ], null];
}

function fiscalSalvarDadosProduto(PDO $pdo, int $idProduto, array $dados): void
{
    $pdo->prepare(
        'UPDATE produtos SET fiscal_ncm = :ncm, fiscal_cfop = :cfop, fiscal_origem = :o, fiscal_unidade = :u, fiscal_cod_tributacao = :ct WHERE id_produto = :id'
    )->execute([':ncm' => $dados['fiscal_ncm'], ':cfop' => $dados['fiscal_cfop'], ':o' => $dados['fiscal_origem'], ':u' => $dados['fiscal_unidade'], ':ct' => $dados['fiscal_cod_tributacao'], ':id' => $idProduto]);
}

/** Bloco "Dados fiscais" dos formulários de produto — só aparece com a nota fiscal ligada. */
function fiscalCamposProduto(PDO $pdo, ?array $p): void
{
    if (!fiscalAtivo($pdo)) {
        return;
    }
    $cfg = fiscalConfig($pdo);
    $v = fn(string $k) => htmlspecialchars((string) ($p[$k] ?? ($_POST[$k] ?? '')));
    echo '<fieldset style="border:1px solid var(--cor-borda); border-radius:10px; padding:12px 16px; margin:14px 0;"><legend style="padding:0 8px; font-weight:600;">Dados fiscais (nota fiscal)</legend>';
    echo '<p style="color:var(--cor-texto-suave); font-size:0.85rem; margin-top:0;">Defina com o seu contador. NCM e grupo tributário são obrigatórios para emitir nota deste produto; o resto usa o padrão de Configurações → Nota fiscal.</p>';
    echo '<label>NCM (8 números)<input type="text" name="fiscal_ncm" inputmode="numeric" maxlength="10" value="' . $v('fiscal_ncm') . '" placeholder="Ex: 61091000"></label>';
    echo '<label>Grupo tributário<input type="text" name="fiscal_cod_tributacao" maxlength="40" value="' . $v('fiscal_cod_tributacao') . '" placeholder="' . htmlspecialchars((string) ($cfg['cod_tributacao_padrao'] ?: 'Código do grupo cadastrado na Brasil NFe')) . '"></label>';
    echo '<label>CFOP (opcional)<input type="text" name="fiscal_cfop" inputmode="numeric" maxlength="4" value="' . $v('fiscal_cfop') . '" placeholder="' . htmlspecialchars((string) $cfg['cfop_padrao']) . '"></label>';
    echo '<label>Origem (opcional)<select name="fiscal_origem"><option value="">Padrão da loja</option>';
    $orig = (string) ($p['fiscal_origem'] ?? ($_POST['fiscal_origem'] ?? ''));
    foreach (FISCAL_ORIGENS as $k => $rot) {
        echo '<option value="' . $k . '"' . ($orig !== '' && (int) $orig === $k ? ' selected' : '') . '>' . htmlspecialchars($rot) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Unidade (opcional)<input type="text" name="fiscal_unidade" maxlength="6" value="' . $v('fiscal_unidade') . '" placeholder="' . htmlspecialchars((string) $cfg['unidade_padrao']) . '"></label>';
    echo '</fieldset>';
}

/** @return array<int,array{id:int,nome:string,faltando:string[]}> produtos ativos sem dados fiscais */
function fiscalProdutosSemDados(PDO $pdo): array
{
    $cfg = fiscalConfig($pdo);
    $r = [];
    foreach ($pdo->query('SELECT id_produto, nome, fiscal_ncm, fiscal_cod_tributacao FROM produtos WHERE ativo = 1 ORDER BY nome')->fetchAll() as $p) {
        if (($f = fiscalProdutoFaltando($p, $cfg)) !== []) {
            $r[] = ['id' => (int) $p['id_produto'], 'nome' => (string) $p['nome'], 'faltando' => $f];
        }
    }

    return $r;
}

// ---- Planejamento da emissão -------------------------------------------------------------------

/** Distribui um valor entre os itens proporcionalmente ao total (o último absorve os centavos). @return float[] por posição */
function fiscalRatear(array $totais, float $valor): array
{
    $r = array_fill(0, count($totais), 0.0);
    $soma = array_sum($totais);
    if ($valor <= 0 || $soma <= 0) {
        return $r;
    }
    $acumulado = 0.0;
    $ultimo = count($totais) - 1;
    foreach ($totais as $k => $t) {
        $r[$k] = $k === $ultimo ? round($valor - $acumulado, 2) : round($valor * $t / $soma, 2);
        $acumulado += $r[$k];
    }

    return $r;
}

/** Venda paga + itens + pagamentos, ou lança FiscalErro. */
function fiscalFonte(PDO $pdo, int $idVenda): array
{
    $st = $pdo->prepare(
        "SELECT v.*, c.nome AS cliente_nome, c.email AS cliente_email
         FROM vendas v LEFT JOIN clientes c ON c.id_cliente = v.id_cliente WHERE v.id_venda = :id"
    );
    $st->execute([':id' => $idVenda]);
    $v = $st->fetch();
    if (!$v) {
        throw new FiscalErro('Venda não encontrada.');
    }
    if ($v['status'] !== 'Pago') {
        throw new FiscalErro('Só vendas pagas podem ter nota fiscal.');
    }

    $st = $pdo->prepare(
        'SELECT iv.*, p.id_produto, p.fiscal_ncm, p.fiscal_cfop, p.fiscal_origem, p.fiscal_unidade, p.fiscal_cod_tributacao
         FROM itens_venda iv
         LEFT JOIN produto_variacoes pv ON pv.id_produto_variacao = iv.id_produto_variacao
         LEFT JOIN produtos p ON p.id_produto = pv.id_produto
         WHERE iv.id_venda = :id ORDER BY iv.id_item'
    );
    $st->execute([':id' => $idVenda]);
    $linhas = [];
    $frete = 0.0;
    foreach ($st->fetchAll() as $l) {
        if ($l['id_produto_variacao'] === null && $l['nome_produto'] === 'Entrega') {
            $frete += (float) $l['subtotal'];
        } else {
            $linhas[] = $l;
        }
    }

    $st = $pdo->prepare('SELECT forma_pagamento AS forma, valor FROM venda_pagamentos WHERE id_venda = :id ORDER BY id_pagamento');
    $st->execute([':id' => $idVenda]);
    $pagamentos = $st->fetchAll();
    $totalPago = round(array_sum(array_map(fn($p) => (float) $p['valor'], $pagamentos)), 2);
    if ($pagamentos === [] || abs($totalPago - (float) $v['valor_total']) > 0.01) {
        $pagamentos = [['forma' => (string) ($v['forma_pagamento'] ?: 'Outros'), 'valor' => (float) $v['valor_total']]];
    }

    return ['venda' => $v, 'linhas' => $linhas, 'frete' => round($frete, 2), 'pagamentos' => $pagamentos];
}

/** CFOP final do item: o do produto (ou o padrão da loja), trocando pra 6xxx se o destino é de outro estado (NF-e). */
function fiscalCfopDoItem(?string $cfopProduto, array $cfg, string $tipo, ?string $ufDestino): string
{
    $cfop = $cfopProduto ?: (string) $cfg['cfop_padrao'];
    if ($tipo === 'nfe' && $ufDestino && $cfg['uf_loja'] && $ufDestino !== $cfg['uf_loja'] && str_starts_with($cfop, '5')) {
        // Consumidor final fora do estado: revenda vira 6108 e produção própria 6107; os demais só trocam o primeiro dígito.
        return ['5102' => '6108', '5101' => '6107'][$cfop] ?? '6' . substr($cfop, 1);
    }

    return $cfop;
}

/**
 * Monta (sem emitir) a nota da venda e aponta o que impede a emissão.
 *
 * @return array{fonte:array, tipo:string, nota:?array, pendencias:array<int,array>, erros:array<string,string>, avisos:string[], emitidas:array<int,array>}
 */
function fiscalPlanejar(PDO $pdo, int $idVenda, array $form = []): array
{
    if (!fiscalPronto($pdo)) {
        throw new FiscalErro('A nota fiscal ainda não está pronta. Conecte a Brasil NFe e aceite a responsabilidade em Configurações → Nota fiscal.');
    }
    $cfg = fiscalConfig($pdo);
    $fonte = fiscalFonte($pdo, $idVenda);
    $tipo = $fonte['venda']['origem'] === 'loja' ? 'nfe' : 'nfce';
    if ($tipo === 'nfce' && (int) $cfg['emite_nfce'] !== 1) {
        throw new FiscalErro('A NFC-e (cupom do PDV) está desligada na configuração da nota fiscal.');
    }
    if ($tipo === 'nfe' && (int) $cfg['emite_nfe'] !== 1) {
        throw new FiscalErro('A NF-e (nota A4 das vendas online) está desligada na configuração da nota fiscal.');
    }

    $emitidas = fiscalNotasDaVenda($pdo, $idVenda);
    $jaTem = array_filter($emitidas, fn($n) => $n['tipo'] === $tipo && in_array($n['status'], ['processando', 'autorizada'], true));
    $erros = $avisos = $pendencias = [];

    // Destinatário
    $doc = trim((string) ($form['doc'] ?? ''));
    $nome = mb_substr(trim((string) ($form['nome'] ?? '')), 0, 140);
    $dest = ['doc' => '', 'nome' => $nome, 'email' => trim((string) ($form['email'] ?? '')), 'ie_indicador' => 9, 'ie' => '', 'endereco' => []];
    if ($doc !== '') {
        $id = fiscalIdentificarDocumento($doc);
        if ($id === null) {
            $erros['doc'] = 'CPF ou CNPJ inválido.';
        } else {
            $dest['doc'] = $id['numero'];
        }
    }
    $uf = '';
    if ($tipo === 'nfe') {
        $e = [
            'cep' => preg_replace('/\D/', '', (string) ($form['cep'] ?? '')), 'logradouro' => trim((string) ($form['logradouro'] ?? '')),
            'numero' => trim((string) ($form['numero'] ?? '')), 'complemento' => trim((string) ($form['complemento'] ?? '')),
            'bairro' => trim((string) ($form['bairro'] ?? '')), 'cidade' => trim((string) ($form['cidade'] ?? '')),
            'uf' => strtoupper(trim((string) ($form['uf'] ?? ''))), 'ibge' => preg_replace('/\D/', '', (string) ($form['ibge'] ?? '')),
        ];
        $dest['endereco'] = $e;
        $uf = $e['uf'];
        $ieInd = (int) ($form['ie_indicador'] ?? 9);
        $dest['ie_indicador'] = in_array($ieInd, [1, 2, 9], true) ? $ieInd : 9;
        $dest['ie'] = preg_replace('/\D/', '', (string) ($form['ie'] ?? ''));
        if ($form !== []) {
            if ($dest['doc'] === '' && !isset($erros['doc'])) { $erros['doc'] = 'Informe o CPF ou CNPJ do destinatário.'; }
            if ($nome === '') { $erros['nome'] = 'Informe o nome do destinatário.'; }
            if (strlen($e['cep']) !== 8) { $erros['cep'] = 'Informe um CEP válido.'; }
            if ($e['logradouro'] === '' || $e['numero'] === '' || $e['bairro'] === '' || $e['cidade'] === '') { $erros['endereco'] = 'Preencha rua, número, bairro e cidade.'; }
            if (!in_array($e['uf'], FISCAL_UFS, true)) { $erros['uf'] = 'Informe o estado (UF).'; }
            if (strlen($e['ibge']) !== 7) { $erros['ibge'] = 'Código do município (IBGE) não encontrado — digite o CEP de novo para o sistema preencher.'; }
            if ($dest['ie_indicador'] === 1 && $dest['ie'] === '') { $erros['ie'] = 'Informe a inscrição estadual do destinatário contribuinte.'; }
        }
        if (empty($cfg['uf_loja'])) {
            $erros['uf_loja'] = 'Informe o estado (UF) da loja em Configurações → Nota fiscal.';
        }
        if ($dest['email'] !== '' && !filter_var($dest['email'], FILTER_VALIDATE_EMAIL)) {
            $erros['email'] = 'E-mail inválido.';
        }
    }

    // Itens
    $itens = [];
    foreach ($fonte['linhas'] as $l) {
        if ($l['id_produto'] === null) {
            $pendencias[] = ['id' => null, 'nome' => (string) $l['nome_produto'], 'faltando' => ['cadastro do produto (item avulso ou removido)']];
            continue;
        }
        $falta = fiscalProdutoFaltando($l, $cfg);
        $cfop = fiscalCfopDoItem($l['fiscal_cfop'], $cfg, $tipo, $uf ?: null);
        if ($tipo === 'nfce' && !in_array($cfop, FISCAL_CFOP_NFCE, true)) {
            $falta[] = 'CFOP aceito em NFC-e';
        }
        if ($falta !== []) {
            $pendencias[] = ['id' => (int) $l['id_produto'], 'nome' => (string) $l['nome_produto'], 'faltando' => $falta];
            continue;
        }
        $itens[] = [
            'nome' => trim($l['nome_produto'] . ($l['descricao_combinacao'] ? ' - ' . $l['descricao_combinacao'] : '')),
            'codigo' => 'P' . (int) $l['id_produto'], 'ncm' => (string) $l['fiscal_ncm'], 'cfop' => $cfop,
            'unidade' => trim((string) $l['fiscal_unidade']) ?: (string) $cfg['unidade_padrao'],
            'quantidade' => (int) $l['quantidade'], 'valor_unitario' => (float) $l['preco_unit'], 'total' => (float) $l['subtotal'], 'desconto' => 0.0, 'frete' => 0.0,
            'origem' => $l['fiscal_origem'] !== null ? (int) $l['fiscal_origem'] : (int) $cfg['origem_padrao'],
            'cod_tributacao' => trim((string) $l['fiscal_cod_tributacao']) ?: (string) $cfg['cod_tributacao_padrao'],
        ];
    }
    if ($tipo === 'nfe' && $fonte['frete'] > 0 && $itens !== []) {
        foreach (fiscalRatear(array_column($itens, 'total'), $fonte['frete']) as $k => $f) {
            $itens[$k]['frete'] = $f;
        }
    }

    $valor = round(array_sum(array_column($itens, 'total')) + ($tipo === 'nfe' ? $fonte['frete'] : 0), 2);
    if ($itens === [] && $pendencias === []) {
        $erros['venda'] = 'Esta venda não tem produtos para a nota.';
    }
    $nota = null;
    if ($itens !== []) {
        $pagamentos = $fonte['pagamentos'];
        if ($tipo === 'nfce' && abs(array_sum(array_map(fn($p) => (float) $p['valor'], $pagamentos)) - $valor) > 0.01) {
            $pagamentos = [['forma' => (string) ($fonte['venda']['forma_pagamento'] ?: 'Outros'), 'valor' => $valor]];
        }
        $nota = ['tipo' => $tipo, 'itens' => $itens, 'valor' => $valor, 'pagamentos' => $pagamentos, 'destinatario' => $dest];
    }
    if ($jaTem) {
        $avisos[] = 'Esta venda já tem ' . ($tipo === 'nfe' ? 'NF-e' : 'NFC-e') . ' emitida ou em processamento.';
    }

    return ['fonte' => $fonte, 'tipo' => $tipo, 'nota' => $nota, 'pendencias' => $pendencias, 'erros' => $erros, 'avisos' => $avisos, 'emitidas' => $emitidas, 'ja_tem' => (bool) $jaTem];
}

/**
 * Emite a nota da venda. Lança FiscalErro (mensagem pro usuário) se algo impedir;
 * devolve a linha de notas_fiscais criada.
 */
function fiscalEmitir(PDO $pdo, int $idVenda, array $form, string $operador): array
{
    $plano = fiscalPlanejar($pdo, $idVenda, $form);
    if ($plano['ja_tem']) {
        throw new FiscalErro('Esta venda já tem uma nota ' . ($plano['tipo'] === 'nfe' ? 'NF-e' : 'NFC-e') . ' emitida ou em processamento.');
    }
    if ($plano['pendencias'] !== []) {
        throw new FiscalErro('Faltam dados fiscais em ' . count($plano['pendencias']) . ' item(ns) da venda. Complete o cadastro dos produtos e tente de novo.');
    }
    if ($plano['erros'] !== []) {
        throw new FiscalErro(implode(' ', array_values($plano['erros'])));
    }
    $nota = $plano['nota'];
    if ($nota === null || $nota['valor'] <= 0) {
        throw new FiscalErro('Não há nada para emitir nesta venda.');
    }

    $cfg = fiscalConfig($pdo);
    $st = $pdo->prepare('SELECT COUNT(*) FROM notas_fiscais WHERE id_venda = :v AND tipo = :t');
    $st->execute([':v' => $idVenda, ':t' => $nota['tipo']]);
    $identificador = sprintf('LJ%d%s%d', $idVenda, strtoupper($nota['tipo']), (int) $st->fetchColumn() + 1);
    $guardar = array_map(fn($i) => ['nome' => $i['nome'], 'quantidade' => $i['quantidade'], 'total' => $i['total']], $nota['itens']);

    try {
        $pdo->prepare(
            "INSERT INTO notas_fiscais (id_venda, tipo, ambiente, status, identificador, valor, destinatario_doc, destinatario_nome, itens_json, token_publico, emitida_por)
             VALUES (:v, :t, :a, 'processando', :i, :val, :doc, :nome, :itens, :tok, :op)"
        )->execute([
            ':v' => $idVenda, ':t' => $nota['tipo'], ':a' => $cfg['ambiente'], ':i' => $identificador, ':val' => $nota['valor'],
            ':doc' => $nota['destinatario']['doc'] ?: null, ':nome' => $nota['destinatario']['nome'] ?: null,
            ':itens' => json_encode(['itens' => $guardar], JSON_UNESCAPED_UNICODE), ':tok' => bin2hex(random_bytes(16)), ':op' => mb_substr($operador, 0, 120),
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? null) === 1062) {
            throw new FiscalErro('Esta nota já está sendo emitida. Aguarde alguns segundos e confira a lista de notas.');
        }
        throw $e;
    }
    $id = (int) $pdo->lastInsertId();
    $nota['identificador'] = $identificador;

    try {
        $res = fiscalGateway($pdo)->emitir($nota);
    } catch (Throwable $e) {
        $res = ['status' => 'processando', 'erro' => 'Ainda sem resposta da Brasil NFe (' . $e->getMessage() . ') A nota será conferida automaticamente.'];
    }
    fiscalAplicar($pdo, $id, $res);

    return fiscalNota($pdo, $id);
}

// ---- Resultado, conferência e cancelamento ------------------------------------------------------

/** Grava o que o adaptador devolveu (status, números, arquivos). */
function fiscalAplicar(PDO $pdo, int $id, array $res): void
{
    $status = (string) ($res['status'] ?? '');
    if (!in_array($status, ['processando', 'autorizada', 'rejeitada', 'cancelada'], true)) {
        return;
    }
    $campos = ['status' => $status];
    foreach (['numero', 'serie', 'chave', 'protocolo'] as $c) {
        if (!empty($res[$c])) {
            $campos[$c] = (string) $res[$c];
        }
    }
    $campos['erro'] = $status === 'autorizada' ? null : (isset($res['erro']) ? mb_substr((string) $res['erro'], 0, 2000) : null);
    if (!empty($res['arquivo_base64'])) {
        $campos['arquivo_base64'] = (string) $res['arquivo_base64'];
        $campos['arquivo_tipo'] = ($res['arquivo_tipo'] ?? 'html') === 'pdf' ? 'pdf' : 'html';
    }
    if (!empty($res['xml_base64'])) {
        $campos['xml_base64'] = (string) $res['xml_base64'];
    }
    $sets = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($campos)));
    if ($status === 'autorizada') {
        $sets .= ', autorizada_em = COALESCE(autorizada_em, NOW())';
    }
    $pdo->prepare("UPDATE notas_fiscais SET $sets WHERE id_nota = :__id")->execute($campos + ['__id' => $id]);
}

/** Confere na Brasil NFe uma nota "processando". @return bool true se a situação mudou */
function fiscalConferir(PDO $pdo, array $nota): bool
{
    $cfg = fiscalConfig($pdo);
    if (empty($cfg['token'])) {
        return false;
    }
    $res = fiscalGateway($pdo, null, (string) $nota['ambiente'])->consultar($nota);
    $status = $res['status'] ?? 'processando';
    if ($status === 'nao_encontrada') {
        $min = (int) $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, criado_em, NOW()) FROM notas_fiscais WHERE id_nota = ' . (int) $nota['id_nota'])->fetchColumn();
        if ($min >= FISCAL_DESISTIR_APOS_MIN) {
            fiscalAplicar($pdo, (int) $nota['id_nota'], ['status' => 'rejeitada', 'erro' => 'A nota não chegou à Brasil NFe. Emita novamente.']);

            return true;
        }

        return false;
    }
    if ($status === 'processando') {
        return false;
    }
    fiscalAplicar($pdo, (int) $nota['id_nota'], $res);

    return true;
}

/** Confere as notas "processando" (de uma venda, ou de todas) que já passaram do tempo mínimo. Sem cron: chamada ao abrir as telas. */
function fiscalConferirPendentes(PDO $pdo, ?int $idVenda = null, int $limite = 10): int
{
    if (!fiscalAtivo($pdo)) {
        return 0;
    }
    $sql = "SELECT * FROM notas_fiscais WHERE status = 'processando' AND criado_em <= DATE_SUB(NOW(), INTERVAL " . FISCAL_CONFERIR_APOS_MIN . ' MINUTE)'
        . ($idVenda !== null ? ' AND id_venda = ' . (int) $idVenda : '') . ' ORDER BY id_nota LIMIT ' . max(1, min($limite, 50));
    $mudou = 0;
    foreach ($pdo->query($sql)->fetchAll() as $n) {
        try {
            $mudou += fiscalConferir($pdo, $n) ? 1 : 0;
        } catch (Throwable $e) {
            error_log('fiscalConferir #' . $n['id_nota'] . ': ' . $e->getMessage());
        }
    }

    return $mudou;
}

/** Cancela uma nota autorizada. Lança FiscalErro. */
function fiscalCancelar(PDO $pdo, int $idNota, string $motivo, string $usuario): void
{
    $n = fiscalNota($pdo, $idNota);
    if (!$n || $n['status'] !== 'autorizada') {
        throw new FiscalErro('Só é possível cancelar uma nota autorizada.');
    }
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 15 || mb_strlen($motivo) > 255) {
        throw new FiscalErro('Explique o motivo com 15 a 255 caracteres (exigência da SEFAZ).');
    }
    if ($n['tipo'] === 'nfce') {
        $min = (int) $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, COALESCE(autorizada_em, criado_em), NOW()) FROM notas_fiscais WHERE id_nota = ' . $idNota)->fetchColumn();
        if ($min > FISCAL_PRAZO_CANCELAR_NFCE_MIN) {
            throw new FiscalErro('O prazo para cancelar a NFC-e é de ' . FISCAL_PRAZO_CANCELAR_NFCE_MIN . ' minutos após a autorização. Procure o seu contador.');
        }
    }
    try {
        $res = fiscalGateway($pdo, null, (string) $n['ambiente'])->cancelar($n, $motivo);
    } catch (RuntimeException $e) {
        throw new FiscalErro($e->getMessage());
    }
    if (($res['status'] ?? '') !== 'cancelada') {
        throw new FiscalErro((string) ($res['erro'] ?? 'A Brasil NFe não aceitou o cancelamento.'));
    }
    $pdo->prepare("UPDATE notas_fiscais SET status = 'cancelada', cancelada_em = NOW(), cancelada_por = :u, motivo_cancelamento = :m WHERE id_nota = :id")
        ->execute([':u' => mb_substr($usuario, 0, 120), ':m' => mb_substr($motivo, 0, 255), ':id' => $idNota]);
}

// ---- Consultas ---------------------------------------------------------------------------------

function fiscalNota(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM notas_fiscais WHERE id_nota = :id');
    $st->execute([':id' => $id]);

    return $st->fetch() ?: null;
}

/** @return array<int,array> notas da venda, sem os arquivos pesados */
function fiscalNotasDaVenda(PDO $pdo, int $idVenda): array
{
    try {
        $st = $pdo->prepare(
            'SELECT id_nota, id_venda, tipo, ambiente, status, identificador, valor, destinatario_doc, destinatario_nome, numero, serie, chave, erro, token_publico,
                    (arquivo_base64 IS NOT NULL) AS tem_arquivo, arquivo_tipo, autorizada_em, criado_em
             FROM notas_fiscais WHERE id_venda = :v ORDER BY id_nota'
        );
        $st->execute([':v' => $idVenda]);

        return $st->fetchAll();
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return [];
        }
        throw $e;
    }
}

/** Nota pelo token do link público. */
function fiscalNotaPublica(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare("SELECT * FROM notas_fiscais WHERE token_publico = :t AND status = 'autorizada'");
    $st->execute([':t' => $token]);

    return $st->fetch() ?: null;
}

/** Selo (rótulo + classe do status-pill) de uma nota. @return array{0:string,1:string} */
function fiscalSelo(array $n): array
{
    $tipo = $n['tipo'] === 'nfe' ? 'NF-e' : 'NFC-e';

    return match ($n['status']) {
        'autorizada' => [$tipo . ' autorizada', 'sucesso'],
        'processando' => [$tipo . ' processando', 'alerta'],
        'rejeitada' => [$tipo . ' recusada', 'erro'],
        'cancelada' => [$tipo . ' cancelada', ''],
        default => [$tipo, ''],
    };
}
