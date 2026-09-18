-- Verificação de WhatsApp (mesmo padrão da verificação de e-mail: código de
-- 6 dígitos, popup, "não recebi" reenvia o mesmo código).
ALTER TABLE clientes
    ADD COLUMN whatsapp_verificado_em DATETIME NULL,
    ADD COLUMN token_verificacao_whatsapp VARCHAR(10) NULL,
    ADD COLUMN token_verificacao_whatsapp_expira_em DATETIME NULL;

-- Configuração no painel_dev: liga/desliga o recurso, e guarda o necessário
-- pra mandar o código pelo WhatsApp via um webhook do n8n -- o workflow do
-- n8n é genérico (o mesmo arquivo serve pra qualquer instalação deste
-- sistema, feita por qualquer dev), então as credenciais da Evolution API
-- de CADA instalação vão dentro da própria chamada ao webhook, nunca fixas
-- dentro do workflow.
ALTER TABLE config_dev
    ADD COLUMN whatsapp_verificacao_ativo TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN evolution_base_url VARCHAR(255) NULL,
    ADD COLUMN evolution_api_key VARCHAR(255) NULL,
    ADD COLUMN evolution_instancia VARCHAR(150) NULL,
    ADD COLUMN n8n_webhook_url VARCHAR(500) NULL;
