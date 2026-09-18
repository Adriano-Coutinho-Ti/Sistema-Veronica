-- Segurança da conta do dev: nome/e-mail pra recuperação, trava progressiva
-- de tentativas de login, e uma senha de recuperação separada (pro dev não
-- ficar trancado fora do sistema se perder acesso ao próprio e-mail também).
--
-- IMPORTANTE: esta migração só cria as colunas. Ela NÃO grava nenhum valor
-- em senha_recuperacao_hash -- esse hash é gerado e gravado manualmente por
-- fora do código (nunca commitado no Git), ver instrução separada.
ALTER TABLE dev_usuarios
    ADD COLUMN nome VARCHAR(100) NULL AFTER usuario,
    ADD COLUMN email VARCHAR(150) NULL AFTER nome,
    ADD COLUMN senha_recuperacao_hash VARCHAR(255) NULL AFTER senha_hash,
    ADD COLUMN tentativas_falhas INT NOT NULL DEFAULT 0,
    ADD COLUMN ciclos_bloqueio INT NOT NULL DEFAULT 0,
    ADD COLUMN bloqueado_ate DATETIME NULL,
    ADD COLUMN bloqueio_seguranca TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN token_redefinicao_senha VARCHAR(64) NULL,
    ADD COLUMN token_redefinicao_expira_em DATETIME NULL;
