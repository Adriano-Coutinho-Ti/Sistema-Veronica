ALTER TABLE produto_variacoes
    ADD COLUMN liberado_em DATETIME NULL AFTER estoque_reservado;
