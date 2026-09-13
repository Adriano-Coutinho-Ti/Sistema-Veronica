ALTER TABLE vendas ADD COLUMN token_recibo VARCHAR(64) NULL;
ALTER TABLE vendas ADD UNIQUE KEY uniq_vendas_token_recibo (token_recibo);
ALTER TABLE config_loja ADD COLUMN imprimir_automatico TINYINT(1) NOT NULL DEFAULT 0;
