CREATE TABLE caixa_sessoes (
    id_caixa INT AUTO_INCREMENT PRIMARY KEY,
    valor_inicial DECIMAL(10,2) NOT NULL,
    aberto_por INT NOT NULL,
    data_abertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    status ENUM('aberto','fechado') NOT NULL DEFAULT 'aberto',
    fechado_por INT NULL,
    data_fechamento DATETIME NULL,
    valor_final_informado DECIMAL(10,2) NULL,
    valor_esperado DECIMAL(10,2) NULL,
    diferenca DECIMAL(10,2) NULL,
    observacao_fechamento VARCHAR(255) NULL,
    FOREIGN KEY (aberto_por) REFERENCES usuarios(id_usuario),
    FOREIGN KEY (fechado_por) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE vendas (
    id_venda INT AUTO_INCREMENT PRIMARY KEY,
    id_caixa INT NOT NULL,
    id_cliente INT NULL,
    id_usuario INT NOT NULL,
    valor_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status ENUM('Reservado','Pago') NOT NULL DEFAULT 'Reservado',
    forma_pagamento VARCHAR(50) NULL,
    id_pagamento_mp VARCHAR(50) NULL,
    data_venda DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_caixa) REFERENCES caixa_sessoes(id_caixa),
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE itens_venda (
    id_item INT AUTO_INCREMENT PRIMARY KEY,
    id_venda INT NOT NULL,
    nome_produto VARCHAR(150) NOT NULL,
    descricao_combinacao VARCHAR(255) NULL,
    id_produto_variacao INT NULL,
    quantidade INT NOT NULL,
    preco_unit DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE venda_pagamentos (
    id_pagamento INT AUTO_INCREMENT PRIMARY KEY,
    id_venda INT NOT NULL,
    forma_pagamento VARCHAR(50) NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    data_pagamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_venda) REFERENCES vendas(id_venda) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE config_pagamento (
    id_config INT PRIMARY KEY,
    mp_access_token VARCHAR(255) NULL,
    mp_refresh_token VARCHAR(255) NULL,
    mp_public_key VARCHAR(255) NULL,
    mp_user_id VARCHAR(50) NULL,
    mp_token_expira DATETIME NULL,
    mp_oauth_nonce VARCHAR(64) NULL,
    mp_oauth_nonce_expira DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO config_pagamento (id_config) VALUES (1);
