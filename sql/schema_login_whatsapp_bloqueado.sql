-- Cliente que troca o numero de WhatsApp com a validacao por WhatsApp
-- desligada no painel_dev nao consegue validar o numero novo -- entao esse
-- numero nao pode mais servir de login (senao ele poderia abrir outra conta
-- e largar a divida na antiga). Ligado na troca, desligado quando o numero
-- for validado por codigo.
ALTER TABLE clientes
    ADD COLUMN login_whatsapp_bloqueado TINYINT(1) NOT NULL DEFAULT 0;
