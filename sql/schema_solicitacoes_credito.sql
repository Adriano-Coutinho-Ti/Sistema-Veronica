CREATE TABLE solicitacoes_credito (
    id_solicitacao INT AUTO_INCREMENT PRIMARY KEY,
    id_cliente INT NOT NULL,
    valor_solicitado DECIMAL(10,2) NOT NULL,
    status ENUM('Pendente', 'Aprovada', 'Rejeitada') NOT NULL DEFAULT 'Pendente',
    valor_aprovado DECIMAL(10,2) NULL,
    observacao_admin VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    respondido_em DATETIME NULL,
    respondido_por INT NULL,
    FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
    FOREIGN KEY (respondido_por) REFERENCES usuarios(id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
