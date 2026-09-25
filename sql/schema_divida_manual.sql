-- Divida do caderno migrada pro sistema: um movimento 'compra' sem venda
-- (id_venda NULL) e sem caixa, com a data original da divida. So precisa de
-- um campo pra descrever o que era (ex: "blusa e calca - caderno de marco").
ALTER TABLE movimentos_credito
    ADD COLUMN observacao VARCHAR(255) NULL;
