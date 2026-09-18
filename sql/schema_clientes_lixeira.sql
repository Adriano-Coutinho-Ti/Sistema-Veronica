-- Lixeira de clientes: excluir um cliente só preenche excluido_em (ele some
-- das listas e perde o acesso à loja); a exclusão definitiva, com todos os
-- registros dele, só acontece de dentro da lixeira e só pra Admin.
ALTER TABLE clientes
    ADD COLUMN excluido_em DATETIME NULL,
    ADD COLUMN excluido_por INT NULL,
    ADD INDEX idx_clientes_excluido_em (excluido_em);
