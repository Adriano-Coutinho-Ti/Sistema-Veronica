-- Conexão do WhatsApp do lojista (QR code, Evolution API). Rodar no banco ANTES de usar o recurso.
-- Sem esta tabela (ou com a loja desconectada) o sistema funciona como se o
-- WhatsApp não existisse: só e-mail para o cliente.
CREATE TABLE whatsapp_conexao (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    instancia VARCHAR(80) NULL,
    estado ENUM('desconectado','conectando','conectado') NOT NULL DEFAULT 'desconectado',
    numero VARCHAR(20) NULL,
    aceite_em DATETIME NULL,
    aceite_por INT NULL,
    conectado_em DATETIME NULL,
    verificado_em DATETIME NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO whatsapp_conexao (id) VALUES (1);
