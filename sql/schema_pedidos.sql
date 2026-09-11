ALTER TABLE vendas
    ADD COLUMN status_entrega ENUM('Aguardando preparo', 'Preparando', 'Pronto', 'Enviado', 'Entregue') NULL AFTER status;
