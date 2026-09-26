-- SuperFrete (cotação de frete + etiquetas). (NuPay fica para uma etapa futura.)
-- Rodar manualmente no banco ANTES de subir o código.
-- Tudo nasce desligado: enquanto o dev não ativar no painel_dev, o sistema
-- continua exatamente como era.

-- ---- Chaves gerais (painel do dev) ----
ALTER TABLE config_dev
    ADD COLUMN superfrete_modo ENUM('desativado','consulta','etiquetas') NOT NULL DEFAULT 'desativado',
    ADD COLUMN superfrete_ambiente ENUM('producao','sandbox') NOT NULL DEFAULT 'producao',
    ADD COLUMN superfrete_token_dev TEXT NULL;

-- ---- Configuração do lojista ----
ALTER TABLE config_loja
    ADD COLUMN superfrete_token TEXT NULL,
    ADD COLUMN superfrete_cep_origem CHAR(8) NULL,
    ADD COLUMN superfrete_servicos VARCHAR(30) NOT NULL DEFAULT '1,2',
    ADD COLUMN superfrete_seguro TINYINT(1) NOT NULL DEFAULT 0;

-- ---- Produto: peso e caixa usada no envio ----
-- caixa_tamanho: 1 envelope, 2 pequena, 3 média, 4 grande, 5 extra grande
ALTER TABLE produtos
    ADD COLUMN peso_gramas INT NULL,
    ADD COLUMN caixa_tamanho TINYINT NULL;

-- ---- Forma de entrega "SuperFrete" (só aparece na loja com o recurso ativo) ----
ALTER TABLE formas_entrega
    ADD COLUMN superfrete TINYINT(1) NOT NULL DEFAULT 0;

INSERT INTO formas_entrega (nome, tipo, prazo_dias, custo, ativo, fixa, superfrete)
VALUES ('SuperFrete', 'entrega', NULL, 0, 1, 0, 1);

-- ---- Dados de envio de cada compra (não ficam no cadastro do cliente) ----
CREATE TABLE vendas_envio (
    id_venda INT NOT NULL PRIMARY KEY,
    servico_id INT NULL,
    servico_nome VARCHAR(60) NULL,
    prazo_dias INT NULL,
    valor_frete DECIMAL(10,2) NOT NULL DEFAULT 0,
    destino_nome VARCHAR(150) NULL,
    destino_documento VARCHAR(14) NULL,
    destino_telefone VARCHAR(15) NULL,
    destino_email VARCHAR(150) NULL,
    destino_cep CHAR(8) NULL,
    destino_endereco VARCHAR(200) NULL,
    destino_numero VARCHAR(20) NULL,
    destino_complemento VARCHAR(100) NULL,
    destino_bairro VARCHAR(100) NULL,
    destino_cidade VARCHAR(100) NULL,
    destino_uf CHAR(2) NULL,
    volume_json TEXT NULL,
    superfrete_order_id VARCHAR(60) NULL,
    superfrete_status VARCHAR(30) NULL,
    superfrete_rastreio VARCHAR(60) NULL,
    superfrete_etiqueta_url VARCHAR(500) NULL,
    superfrete_valor_etiqueta DECIMAL(10,2) NULL,
    nota_numero VARCHAR(20) NULL,
    nota_chave CHAR(44) NULL,
    etiqueta_gerada_em DATETIME NULL,
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

