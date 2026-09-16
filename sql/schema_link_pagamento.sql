-- Guarda o link de pagamento (init_point) devolvido pelo Mercado Pago na hora
-- de gerar o checkout -- sem isso não tinha como mostrar de novo pro cliente
-- se ele voltasse pro checkout.php com um pagamento já em andamento (só
-- restava mandar ele gerar um pagamento novo do zero).
ALTER TABLE vendas
    ADD COLUMN link_pagamento_mp VARCHAR(500) NULL AFTER pagamento_expira_em;
