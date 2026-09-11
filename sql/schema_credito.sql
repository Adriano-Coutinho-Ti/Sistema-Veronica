ALTER TABLE clientes
    ADD COLUMN limite_credito DECIMAL(10,2) NOT NULL DEFAULT 0,
    ADD COLUMN saldo_devedor DECIMAL(10,2) NOT NULL DEFAULT 0;

CREATE TABLE movimentos_credito (
    id_movimento INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    tipo ENUM('compra', 'pagamento') NOT NULL,
    status ENUM('Confirmado', 'Pendente', 'Cancelado') NOT NULL DEFAULT 'Confirmado',
    valor DECIMAL(10,2) NOT NULL,
    id_venda INT NULL,
    forma_pagamento VARCHAR(50) NULL,
    id_pagamento_mp VARCHAR(50) NULL,
    criado_por INT NULL,
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda),
    FOREIGN KEY (criado_por) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
