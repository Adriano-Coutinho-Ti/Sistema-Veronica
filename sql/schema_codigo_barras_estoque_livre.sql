-- Amplia o campo codigo (era CHAR(3), só cabia o codigo gerado automaticamente)
-- pra caber tambem um codigo de barras real digitado/escaneado pelo lojista.
-- Mantem a mesma UNIQUE KEY (uniq_produtos_codigo) e NULL.
ALTER TABLE produtos MODIFY COLUMN codigo VARCHAR(30) NULL;

-- Controle de estoque por produto: quando 0, o produto pode ser vendido
-- livremente (loja online e PDV), sem checar nem descontar as quantidades
-- de produto_variacoes. Todo produto existente continua com o
-- comportamento atual (1 = estoque controlado normalmente).
ALTER TABLE produtos ADD COLUMN estoque_gerenciado TINYINT(1) NOT NULL DEFAULT 1;
