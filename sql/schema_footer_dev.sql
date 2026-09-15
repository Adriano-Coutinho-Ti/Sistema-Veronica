-- Nome padrao do sistema para instalacoes novas passa a ser "Sistema CoderNex"
-- (nome da empresa dona do sistema) em vez de "Sistema Veronica" (nome do
-- projeto). Nao muda o valor ja salvo nesta instalacao -- se quiser trocar
-- o nome aqui, use o proprio formulario em painel_dev/index.php, nao precisa
-- de SQL pra isso.
ALTER TABLE config_dev
    MODIFY COLUMN nome_sistema VARCHAR(150) NOT NULL DEFAULT 'Sistema CoderNex';

-- Credito "Desenvolvido por ..." no rodape da loja -- ate agora fixo em
-- "CoderNex" / codernex.com.br no codigo. Cada desenvolvedor que instalar
-- este sistema pra outro cliente pode trocar por seu proprio nome/link pelo
-- painel_dev. Em branco = continua mostrando CoderNex (fallback no codigo).
ALTER TABLE config_dev
    ADD COLUMN footer_nome VARCHAR(150) NULL AFTER smtp_from_name,
    ADD COLUMN footer_link VARCHAR(255) NULL AFTER footer_nome;
