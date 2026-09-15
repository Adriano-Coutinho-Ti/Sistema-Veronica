-- Painel do desenvolvedor: login proprio, separado da tabela usuarios da loja
-- (nao usa e-mail, so um usuario simples). Senha ja vai com hash pronto --
-- nunca em texto puro, mesmo padrao da tabela usuarios.
CREATE TABLE dev_usuarios (
    id_dev_usuario INT AUTO_INCREMENT PRIMARY KEY,
    usuario VARCHAR(50) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- usuario: DevMaster / senha: 2026@DevSys (hash bcrypt abaixo, gerado com
-- password_hash() do PHP -- a senha em si nunca fica salva em lugar nenhum).
INSERT INTO dev_usuarios (usuario, senha_hash) VALUES ('DevMaster', '$2y$12$r2YlA3uugU9jA3XMVbHbxO1a.eWX3NYXczLQ5XHCF43PTGlUXdQEG');

-- Configuracoes que o desenvolvedor pode editar pelo painel_dev -- diferente
-- de host/usuario/senha/nome do banco (que continuam so no arquivo
-- brechodaveve_config_credenciais.php, fora do site, por nao ter como
-- funcionar de outro jeito), estas aqui so sao usadas DEPOIS que o sistema
-- ja esta rodando e conectado ao banco, entao podem ficar aqui com
-- seguranca. Ficam em branco ate o desenvolvedor preencher pelo painel;
-- enquanto estiverem em branco, o sistema continua usando os valores do
-- arquivo de credenciais (MP_APP_CLIENT_ID etc, SMTP_* ) como estao hoje.
CREATE TABLE config_dev (
    id_config INT PRIMARY KEY,
    nome_sistema VARCHAR(150) NOT NULL DEFAULT 'Sistema Veronica',
    mp_app_client_id VARCHAR(100) NULL,
    mp_app_client_secret VARCHAR(100) NULL,
    mp_webhook_secret VARCHAR(150) NULL,
    smtp_host VARCHAR(150) NULL,
    smtp_port INT NULL,
    smtp_user VARCHAR(150) NULL,
    smtp_pass VARCHAR(255) NULL,
    smtp_from_email VARCHAR(150) NULL,
    smtp_from_name VARCHAR(150) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO config_dev (id_config) VALUES (1);
