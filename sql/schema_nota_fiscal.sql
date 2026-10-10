-- Nota fiscal de produto pela Brasil NFe: NFC-e (cupom) nas vendas do PDV e
-- NF-e (A4) nas vendas online. Função nativa: o lojista liga e desliga sozinho.
-- Rodar no banco ANTES de usar. Desligada (padrão), o sistema fica como sempre foi.

-- Dados fiscais de cada produto (NCM e grupo tributário são exigidos só na hora de emitir).
ALTER TABLE produtos
    ADD COLUMN fiscal_ncm CHAR(8) NULL,
    ADD COLUMN fiscal_cfop CHAR(4) NULL,
    ADD COLUMN fiscal_origem TINYINT UNSIGNED NULL,
    ADD COLUMN fiscal_unidade VARCHAR(6) NULL,
    ADD COLUMN fiscal_cod_tributacao VARCHAR(40) NULL;

-- Configuração do lojista (linha única).
CREATE TABLE fiscal_config (
    id_config TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 0,
    token VARCHAR(300) NULL,
    ambiente ENUM('homologacao','producao') NOT NULL DEFAULT 'homologacao',
    conectado_em DATETIME NULL,
    emite_nfce TINYINT(1) NOT NULL DEFAULT 1,
    emite_nfe TINYINT(1) NOT NULL DEFAULT 1,
    aceite_em DATETIME NULL,
    aceite_por INT NULL,
    uf_loja CHAR(2) NULL,
    cfop_padrao CHAR(4) NOT NULL DEFAULT '5102',
    origem_padrao TINYINT UNSIGNED NOT NULL DEFAULT 0,
    unidade_padrao VARCHAR(6) NOT NULL DEFAULT 'UN',
    cod_tributacao_padrao VARCHAR(40) NULL,
    PRIMARY KEY (id_config)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO fiscal_config (id_config) VALUES (1);

-- Livro das notas emitidas (uma linha por NFC-e ou NF-e).
CREATE TABLE notas_fiscais (
    id_nota INT AUTO_INCREMENT PRIMARY KEY,
    id_venda INT NOT NULL,
    tipo ENUM('nfce','nfe') NOT NULL,
    ambiente ENUM('homologacao','producao') NOT NULL,
    status ENUM('processando','autorizada','rejeitada','cancelada') NOT NULL DEFAULT 'processando',
    identificador VARCHAR(80) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    destinatario_doc VARCHAR(14) NULL,
    destinatario_nome VARCHAR(140) NULL,
    numero VARCHAR(30) NULL,
    serie VARCHAR(10) NULL,
    chave VARCHAR(60) NULL,
    protocolo VARCHAR(60) NULL,
    arquivo_base64 MEDIUMTEXT NULL,
    arquivo_tipo VARCHAR(8) NULL,
    xml_base64 MEDIUMTEXT NULL,
    erro TEXT NULL,
    itens_json MEDIUMTEXT NULL,
    token_publico CHAR(32) NOT NULL,
    emitida_por VARCHAR(120) NOT NULL,
    autorizada_em DATETIME NULL,
    cancelada_em DATETIME NULL,
    cancelada_por VARCHAR(120) NULL,
    motivo_cancelamento VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_nota_identificador (identificador),
    UNIQUE KEY uniq_nota_token (token_publico),
    KEY idx_nota_venda (id_venda),
    KEY idx_nota_pendente (status, criado_em),
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
