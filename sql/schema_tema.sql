ALTER TABLE config_loja
    ADD COLUMN tema ENUM('claro', 'escuro', 'personalizado') NOT NULL DEFAULT 'claro',
    ADD COLUMN cor_fundo CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN cor_texto CHAR(7) NOT NULL DEFAULT '#1F2937';
