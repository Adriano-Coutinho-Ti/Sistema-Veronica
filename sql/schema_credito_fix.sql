ALTER TABLE movimentos_credito
    ADD COLUMN id_caixa INT NULL,
    ADD FOREIGN KEY (id_caixa) REFERENCES caixa_sessoes(id_caixa);
