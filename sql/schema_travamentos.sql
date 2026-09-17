-- Um único campo em vez de duas flags booleanas -- já impede por construção
-- os dois travamentos ficarem ativos ao mesmo tempo (NULL = nenhum,
-- 'pagamento' bloqueia só o sistema de gestão, 'manutencao' bloqueia tudo).
ALTER TABLE config_dev
    ADD COLUMN travamento_ativo ENUM('pagamento', 'manutencao') NULL DEFAULT NULL,
    ADD COLUMN travamento_pagamento_mensagem TEXT NULL,
    ADD COLUMN travamento_manutencao_mensagem TEXT NULL;
