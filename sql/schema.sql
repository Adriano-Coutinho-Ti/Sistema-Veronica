CREATE TABLE usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('Admin','Funcionario') NOT NULL DEFAULT 'Funcionario',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE clientes (
    id_cliente INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL UNIQUE,
    email VARCHAR(160) NULL,
    endereco VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categorias (
    id_categoria INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE variacoes (
    id_variacao INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE variacao_valores (
    id_valor INT AUTO_INCREMENT PRIMARY KEY,
    id_variacao INT NOT NULL,
    valor VARCHAR(100) NOT NULL,
    FOREIGN KEY (id_variacao) REFERENCES variacoes(id_variacao) ON DELETE CASCADE,
    UNIQUE KEY uniq_valor_por_variacao (id_variacao, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE categoria_variacoes (
    id_categoria INT NOT NULL,
    id_variacao INT NOT NULL,
    PRIMARY KEY (id_categoria, id_variacao),
    FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria) ON DELETE CASCADE,
    FOREIGN KEY (id_variacao) REFERENCES variacoes(id_variacao) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produtos (
    id_produto INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT NULL,
    id_categoria INT NOT NULL,
    condicao ENUM('novo','usado') NOT NULL DEFAULT 'usado',
    preco_base DECIMAL(10,2) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_categoria) REFERENCES categorias(id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produto_variacoes (
    id_produto_variacao INT AUTO_INCREMENT PRIMARY KEY,
    id_produto INT NOT NULL,
    preco DECIMAL(10,2) NULL,
    estoque INT NOT NULL DEFAULT 0,
    FOREIGN KEY (id_produto) REFERENCES produtos(id_produto) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produto_variacao_valores (
    id_produto_variacao INT NOT NULL,
    id_valor INT NOT NULL,
    PRIMARY KEY (id_produto_variacao, id_valor),
    FOREIGN KEY (id_produto_variacao) REFERENCES produto_variacoes(id_produto_variacao) ON DELETE CASCADE,
    FOREIGN KEY (id_valor) REFERENCES variacao_valores(id_valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE produto_fotos (
    id_foto INT AUTO_INCREMENT PRIMARY KEY,
    id_produto INT NOT NULL,
    ordem TINYINT NOT NULL,
    caminho_arquivo VARCHAR(255) NOT NULL,
    FOREIGN KEY (id_produto) REFERENCES produtos(id_produto) ON DELETE CASCADE,
    UNIQUE KEY uniq_ordem_por_produto (id_produto, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE config_loja (
    id_config INT PRIMARY KEY,
    nome_loja VARCHAR(150) NOT NULL DEFAULT 'Minha Loja',
    logo_arquivo VARCHAR(255) NULL,
    cor_primaria CHAR(7) NOT NULL DEFAULT '#8B5CF6',
    cor_secundaria CHAR(7) NOT NULL DEFAULT '#F472B6'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO config_loja (id_config) VALUES (1);

CREATE TABLE formas_entrega (
    id_entrega INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    tipo ENUM('retirada','entrega') NOT NULL,
    prazo_dias INT NULL,
    custo DECIMAL(10,2) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    fixa TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO formas_entrega (nome, tipo, prazo_dias, custo, ativo, fixa)
VALUES ('Retirar na loja', 'retirada', 5, 0, 1, 1);
