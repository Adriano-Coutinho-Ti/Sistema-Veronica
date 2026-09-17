-- Qual maquininha Point (terminal_id, vindo de GET /terminals/v1/list) cada
-- caixa numerado usa pra cobrar débito/crédito. Uma maquininha pode ser
-- reaproveitada em mais de um caixa (não há UNIQUE em terminal_id) -- é o
-- cenário normal de uma loja pequena com só uma maquininha física atendendo
-- todos os caixas. Caixa sem linha aqui não tem maquininha vinculada e
-- continua com lançamento manual de Débito/Crédito, como sempre foi.
CREATE TABLE caixa_terminais_point (
    numero_caixa INT PRIMARY KEY,
    terminal_id VARCHAR(150) NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Id da Order (formato "ORD...", endpoint /v1/orders) de um pagamento via
-- maquininha em andamento -- guardado separado de id_pagamento_mp porque é
-- um recurso diferente na API do Mercado Pago (Order, não Payment), embora
-- os dois sirvam ao mesmo propósito de "referência pra consultar o status
-- depois".
ALTER TABLE vendas
    ADD COLUMN id_order_mp VARCHAR(60) NULL AFTER id_pagamento_mp;
