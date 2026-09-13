ALTER TABLE produtos ADD COLUMN codigo CHAR(3) NULL;
ALTER TABLE produtos ADD UNIQUE KEY uniq_produtos_codigo (codigo);
