-- Login da loja online passa a ser por e-mail em vez de WhatsApp. E-mail e
-- WhatsApp continuam ambos únicos (UNIQUE permite múltiplos NULL, então
-- clientes sem e-mail cadastrado continuam existindo sem problema).
ALTER TABLE clientes ADD UNIQUE KEY uniq_clientes_email (email);
