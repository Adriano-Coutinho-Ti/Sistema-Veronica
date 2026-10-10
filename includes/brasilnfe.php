<?php

/**
 * Adaptador da Brasil NFe (API 2.0) para emissão de nota fiscal de PRODUTO:
 * NFC-e (modelo 65, cupom do PDV) e NF-e (modelo 55, DANFE em A4, vendas online).
 * Escrito a partir da documentação pública; ainda NÃO foi homologado com
 * certificado A1 — os nomes de campos de NF-e (endereço, frete) precisam ser
 * conferidos na primeira emissão em homologação.
 *
 * Endpoint: https://api.brasilnfe.com.br/services/fiscal/<Operação>, POST JSON,
 * cabeçalho "Token" da empresa, limite de 60 requisições por minuto.
 */
final class BrasilNfeGateway
{
    public const BASE = 'https://api.brasilnfe.com.br/services/fiscal/';

    /** @var callable */
    private $http;

    /** @param ?callable $http (string $url, array $payload, string $token): array{status:int, corpo:?array} — troca nos testes */
    public function __construct(private string $token, private string $ambiente = 'homologacao', ?callable $http = null)
    {
        $this->http = $http ?? [self::class, 'curl'];
    }

    private function tipoAmbiente(): int
    {
        return $this->ambiente === 'producao' ? 1 : 2;
    }

    /** @return array{ok:bool, mensagem:string} */
    public function testarConexao(): array
    {
        try {
            $r = $this->enviar('ConsultarNotaFiscal', ['IdentificadorInterno' => 'TESTE-CONEXAO', 'TipoAmbiente' => $this->tipoAmbiente()]);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'mensagem' => $e->getMessage()];
        }
        if ($r['status'] === 401 || $r['status'] === 403) {
            return ['ok' => false, 'mensagem' => 'Token recusado pela Brasil NFe. Confira se copiou o token da empresa e se ele está ativo.'];
        }
        if ($r['status'] >= 200 && $r['status'] < 300 && is_array($r['corpo']) && !isset($r['corpo']['erros'][0])) {
            return ['ok' => true, 'mensagem' => 'Conexão com a Brasil NFe confirmada.'];
        }

        return ['ok' => false, 'mensagem' => self::erroDe($r['corpo'] ?? []) ?: 'A Brasil NFe não aceitou o token (resposta ' . $r['status'] . ').'];
    }

    /** @return array<string,mixed> status: autorizada|processando|rejeitada (+ numero, serie, chave, protocolo, arquivos) */
    public function emitir(array $nota): array
    {
        $r = $this->enviar('EnviarNotaFiscal', $nota['tipo'] === 'nfe' ? $this->montarNfe($nota) : $this->montarNfce($nota));

        return $this->lerEmissao($r);
    }

    /** @return array<string,mixed> */
    public function consultar(array $nota): array
    {
        $r = $this->enviar('ConsultarNotaFiscal', [
            'IdentificadorInterno' => (string) $nota['identificador'],
            'TipoAmbiente' => $this->tipoAmbiente(),
            'ModeloDocumento' => $nota['tipo'] === 'nfe' ? 55 : 65,
            'RetornarArquivos' => true,
        ]);
        $c = $r['corpo'] ?? [];
        if ($r['status'] >= 400 || empty($c['Encontrada'])) {
            return ['status' => 'nao_encontrada'];
        }
        $n = $c['Nota'] ?? [];
        $res = [
            'numero' => isset($n['Numero']) ? (string) $n['Numero'] : null,
            'serie' => isset($n['Serie']) ? (string) $n['Serie'] : null,
            'chave' => $n['Chave'] ?? null,
            'protocolo' => $n['NumeroProtocolo'] ?? null,
        ];
        $status = (int) ($n['Status'] ?? 0);
        if ($status === 1) {
            return ['status' => 'autorizada'] + $res + self::arquivos($n['Base64File'] ?? null, $n['Base64Xml'] ?? null);
        }
        if ($status === 2) {
            return ['status' => 'cancelada'] + $res;
        }
        if ($status === 3 || $status === 4) {
            return ['status' => 'rejeitada', 'erro' => (string) ($n['DsStatusResposta'] ?? ($status === 4 ? 'Uso denegado.' : 'Nota rejeitada.'))] + $res;
        }

        return ['status' => 'processando'] + $res;
    }

    /** @return array{status:string, erro?:string, aviso?:?string, protocolo?:?string} */
    public function cancelar(array $nota, string $motivo): array
    {
        $r = $this->enviar('CancelarNotaFiscal', ['ChaveNF' => (string) $nota['chave'], 'Justificativa' => $motivo]);
        $c = $r['corpo'] ?? [];
        $status = (int) ($c['Status'] ?? 0);
        if ($r['status'] < 400 && ($status === 1 || $status === 2)) {
            return ['status' => 'cancelada', 'protocolo' => $c['NuProtocolo'] ?? null, 'aviso' => $status === 2 ? 'O cancelamento está em processamento na Brasil NFe.' : null];
        }

        return ['status' => 'rejeitada', 'erro' => self::erroDe($c) ?: (string) ($c['DsMotivo'] ?? 'Cancelamento recusado.')];
    }

    // ---- Montagem dos pedidos -------------------------------------------------------------

    private function produtos(array $itens): array
    {
        $produtos = [];
        foreach ($itens as $i) {
            $p = [
                'NmProduto' => (string) $i['nome'],
                'CodProdutoServico' => (string) $i['codigo'],
                'NCM' => (string) $i['ncm'],
                'CFOP' => (int) $i['cfop'],
                'UnidadeComercial' => (string) $i['unidade'],
                'Quantidade' => (float) $i['quantidade'],
                'ValorUnitario' => round((float) $i['valor_unitario'], 2),
                'ValorTotal' => round((float) $i['total'], 2),
                'OrigemProduto' => (int) $i['origem'],
                'CodTributacao' => (string) $i['cod_tributacao'],
            ];
            if ((float) ($i['desconto'] ?? 0) > 0) {
                $p['ValorDesconto'] = round((float) $i['desconto'], 2);
            }
            if ((float) ($i['frete'] ?? 0) > 0) {
                $p['ValorFrete'] = round((float) $i['frete'], 2);
            }
            $produtos[] = $p;
        }

        return $produtos;
    }

    private function pagamentos(array $pagamentos): array
    {
        $codigos = ['Dinheiro' => '01', 'Crédito' => '03', 'Débito' => '04', 'Linha de Crédito' => '05', 'Pix' => '17'];
        $linhas = [];
        foreach ($pagamentos as $p) {
            $forma = (string) $p['forma'];
            $linha = ['IndicadorPagamento' => 0, 'FormaPagamento' => $codigos[$forma] ?? '99', 'VlPago' => round((float) $p['valor'], 2), 'VlTroco' => 0];
            if (!isset($codigos[$forma])) {
                $linha['Descricao'] = mb_substr($forma, 0, 60) ?: 'Outros';
            }
            $linhas[] = $linha;
        }

        return $linhas;
    }

    /** @return array<string,mixed> */
    public function montarNfce(array $nota): array
    {
        $corpo = [
            'TipoAmbiente' => (string) $this->tipoAmbiente(),
            'ModeloDocumento' => 65,
            'NaturezaOperacao' => 'Venda ao Consumidor',
            'ConsumidorFinal' => true,
            'IndicadorPresenca' => 1,
            'Finalidade' => 1,
            'EnviarEmail' => false,
            'IdentificadorInterno' => (string) $nota['identificador'],
            'ValorTotal' => round((float) $nota['valor'], 2),
            'Produtos' => $this->produtos($nota['itens']),
            'Pagamentos' => $this->pagamentos($nota['pagamentos']),
        ];
        if (!empty($nota['destinatario']['doc'])) {
            $corpo['Cliente'] = ['CpfCnpj' => (string) $nota['destinatario']['doc']];
            if (!empty($nota['destinatario']['nome'])) {
                $corpo['Cliente']['NmCliente'] = (string) $nota['destinatario']['nome'];
            }
        }

        return $corpo;
    }

    /** @return array<string,mixed> NF-e modelo 55, DANFE retrato em A4 (venda não presencial, consumidor final). */
    public function montarNfe(array $nota): array
    {
        $d = $nota['destinatario'];
        $e = $d['endereco'];
        $cliente = [
            'CpfCnpj' => (string) $d['doc'],
            'NmCliente' => (string) $d['nome'],
            'IndicadorIe' => (int) $d['ie_indicador'],
            'Endereco' => [
                'Logradouro' => (string) $e['logradouro'],
                'Numero' => (string) $e['numero'],
                'Bairro' => (string) $e['bairro'],
                'CodMunicipio' => (string) $e['ibge'],
                'Municipio' => (string) $e['cidade'],
                'Uf' => (string) $e['uf'],
                'Cep' => (string) $e['cep'],
            ],
        ];
        if (!empty($e['complemento'])) {
            $cliente['Endereco']['Complemento'] = (string) $e['complemento'];
        }
        if ((int) $d['ie_indicador'] === 1 && !empty($d['ie'])) {
            $cliente['Ie'] = (string) $d['ie'];
        }
        if (!empty($d['email'])) {
            $cliente['Email'] = (string) $d['email'];
        }

        $temFrete = array_sum(array_column($nota['itens'], 'frete')) > 0;

        return [
            'TipoAmbiente' => $this->tipoAmbiente(),
            'ModeloDocumento' => 55,
            'NaturezaOperacao' => 'Venda de Mercadoria',
            'ConsumidorFinal' => true,
            'IndicadorPresenca' => 2,
            'Finalidade' => 1,
            'TipoDanfe' => 1,
            'EnviarEmail' => false,
            'IdentificadorInterno' => (string) $nota['identificador'],
            'ValorTotal' => round((float) $nota['valor'], 2),
            'Cliente' => $cliente,
            'Produtos' => $this->produtos($nota['itens']),
            'Pagamentos' => $this->pagamentos($nota['pagamentos']),
            'Transporte' => ['ModalidadeFrete' => $temFrete ? 0 : 9],
        ];
    }

    // ---- Leitura das respostas ------------------------------------------------------------

    /** @param array{status:int, corpo:?array} $r */
    private function lerEmissao(array $r): array
    {
        $c = $r['corpo'] ?? [];
        $ret = $c['ReturnNF'] ?? [];
        if ($r['status'] < 400 && !empty($ret['Ok'])) {
            return [
                'status' => 'autorizada',
                'numero' => isset($ret['Numero']) ? (string) $ret['Numero'] : null,
                'serie' => isset($ret['Serie']) ? (string) $ret['Serie'] : null,
                'chave' => $ret['ChaveNF'] ?? null,
                'protocolo' => $ret['NumeroProtocolo'] ?? null,
            ] + self::arquivos($c['Base64File'] ?? null, $c['Base64Xml'] ?? null);
        }
        $erro = self::erroDe($c);
        if ($erro === '' && !empty($ret['DsStatusRespostaSefaz'])) {
            $erro = 'Rejeição ' . (int) ($ret['CodStatusRespostaSefaz'] ?? 0) . ': ' . $ret['DsStatusRespostaSefaz'];
        }
        if ($erro === '' && $r['status'] < 400 && !empty($ret['ChaveNF'])) {
            // Contingência: a nota já vale, o protocolo chega depois (a conferência fecha).
            return ['status' => 'processando', 'chave' => $ret['ChaveNF'], 'numero' => isset($ret['Numero']) ? (string) $ret['Numero'] : null] + self::arquivos($c['Base64File'] ?? null, $c['Base64Xml'] ?? null);
        }

        return ['status' => 'rejeitada', 'erro' => $erro !== '' ? $erro : 'A Brasil NFe não autorizou a nota (resposta ' . $r['status'] . ').'];
    }

    /** @return array{arquivo_base64?:string, arquivo_tipo?:string, xml_base64?:string} */
    private static function arquivos(?string $documento, ?string $xml): array
    {
        $r = [];
        if ($documento !== null && $documento !== '') {
            $bin = base64_decode($documento, true);
            if ($bin !== false) {
                $r['arquivo_base64'] = $documento;
                $r['arquivo_tipo'] = str_starts_with($bin, '%PDF') ? 'pdf' : 'html';
            }
        }
        if ($xml !== null && $xml !== '') {
            $r['xml_base64'] = $xml;
        }

        return $r;
    }

    /** Junta as mensagens de erro da resposta (campo Error, lista erros[] ou message). */
    private static function erroDe(array $c): string
    {
        $partes = [];
        if (!empty($c['Error']) && is_string($c['Error'])) {
            $partes[] = $c['Error'];
        }
        foreach ((array) ($c['erros'] ?? []) as $e) {
            if (is_array($e)) {
                $partes[] = trim(($e['codigo'] ?? '') . ' ' . ($e['descricao'] ?? '') . (!empty($e['correcao']) ? ' — ' . $e['correcao'] : ''));
            }
        }
        foreach (['message', 'Message', 'mensagem'] as $k) {
            if (!empty($c[$k]) && is_string($c[$k])) {
                $partes[] = $c[$k];
            }
        }

        return mb_substr(implode(' | ', array_filter($partes)), 0, 600);
    }

    // ---- HTTP -----------------------------------------------------------------------------

    /** @return array{status:int, corpo:?array} */
    private function enviar(string $operacao, array $payload): array
    {
        $r = ($this->http)(self::BASE . $operacao, $payload, $this->token);
        if ($r['status'] === 429) {
            throw new RuntimeException('A Brasil NFe pediu para aguardar (limite de 60 requisições por minuto). Tente de novo em instantes.');
        }
        if ($r['status'] >= 500) {
            throw new RuntimeException('A Brasil NFe está indisponível no momento (resposta ' . $r['status'] . ').');
        }

        return $r;
    }

    /** @return array{status:int, corpo:?array} */
    public static function curl(string $url, array $payload, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Token: ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 90,
        ]);
        $bruto = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $erro = curl_error($ch);
        if ($bruto === false) {
            throw new RuntimeException('Sem resposta da Brasil NFe: ' . ($erro !== '' ? $erro : 'falha de conexão') . '.');
        }
        $corpo = json_decode((string) $bruto, true);

        return ['status' => $status, 'corpo' => is_array($corpo) ? $corpo : null];
    }
}
