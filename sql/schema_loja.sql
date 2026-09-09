ALTER TABLE vendas
    MODIFY COLUMN id_caixa INT NULL,
    MODIFY COLUMN id_usuario INT NULL,
    MODIFY COLUMN status ENUM('Reservado','Pago','Cancelado') NOT NULL DEFAULT 'Reservado',
    ADD COLUMN origem ENUM('pdv','loja') NOT NULL DEFAULT 'pdv',
    ADD COLUMN id_entrega INT NULL,
    ADD FOREIGN KEY (id_entrega) REFERENCES formas_entrega(id_entrega);

ALTER TABLE clientes ADD COLUMN senha_hash VARCHAR(255) NULL;

ALTER TABLE produto_variacoes ADD COLUMN estoque_reservado INT NOT NULL DEFAULT 0;

ALTER TABLE config_loja ADD COLUMN prazo_reserva_minutos INT NOT NULL DEFAULT 15;
