-- Tempo pra concluir o pagamento no Mercado Pago depois de clicar em pagar,
-- hoje fixo em 10 minutos no código (loja/ajax/gerar_checkout.php) -- vira
-- configurável pelo lojista, ao lado do prazo do carrinho que já existe.
ALTER TABLE config_loja
    ADD COLUMN prazo_pagamento_minutos INT NOT NULL DEFAULT 10 AFTER prazo_reserva_minutos;
