ALTER TABLE config_loja
    ADD COLUMN whatsapp_loja VARCHAR(20) NULL,
    ADD COLUMN endereco_loja VARCHAR(255) NULL,
    ADD COLUMN horario_atendimento VARCHAR(255) NULL,
    ADD COLUMN email_loja VARCHAR(160) NULL;
