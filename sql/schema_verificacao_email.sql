-- Verificação de e-mail por link (evita cadastro com e-mail falso). NULL em
-- email_verificado_em = não confirmado ainda; enquanto assim, o cliente não
-- consegue abrir o carrinho nem adicionar itens (mas continua navegando
-- normalmente pelo resto da loja).
ALTER TABLE clientes
    ADD COLUMN email_verificado_em DATETIME NULL,
    ADD COLUMN token_verificacao_email VARCHAR(64) NULL,
    ADD COLUMN token_verificacao_expira_em DATETIME NULL,
    ADD UNIQUE KEY uniq_clientes_token_verificacao (token_verificacao_email);

-- Clientes que já usavam a loja de verdade (e-mail E senha já cadastrados)
-- antes dessa funcionalidade existir não devem ficar bloqueados
-- retroativamente por uma verificação que nunca foi pedida a eles. Quem só
-- tem e-mail mas nunca ativou a conta (senha_hash NULL) recebe o e-mail de
-- verificação normalmente na hora que ativar.
UPDATE clientes SET email_verificado_em = NOW() WHERE email IS NOT NULL AND senha_hash IS NOT NULL;
