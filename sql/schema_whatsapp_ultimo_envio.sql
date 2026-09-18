-- Corrige a trava de reenvio do código por WhatsApp: precisa de um carimbo
-- próprio de "quando foi o último ENVIO de verdade" (não dá pra usar
-- token_verificacao_whatsapp_expira_em pra isso, porque reenviar o mesmo
-- código -- de propósito -- não mexe na expiração, então a trava calculada
-- a partir dela só funcionava nos primeiros 10min após a criação original
-- do código, ficando destravada pelo resto da validade de 30min).
ALTER TABLE clientes
    ADD COLUMN whatsapp_ultimo_envio_em DATETIME NULL;
