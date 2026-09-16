-- Percentual da taxa de marketplace cobrada pelo sistema em toda venda via
-- Mercado Pago (loja, PDV e pagamento de dívida) -- antes fixo em 1% no
-- código, agora configurável pelo painel_dev. Guardado como percentual
-- (1.00 = 1%), não como fração, pra bater com o que aparece no formulário.
ALTER TABLE config_dev
    ADD COLUMN marketplace_fee_percentual DECIMAL(5,2) NOT NULL DEFAULT 1.00 AFTER mp_webhook_secret;
