-- Quantos caixas físicos o sistema vai trabalhar (min 1, default 1 = mesmo
-- comportamento de hoje, sem tela de seleção) e se um caixa aberto pode ser
-- atendido por mais de um usuário ao mesmo tempo -- configurável no painel
-- do dev, junto das outras configurações de instalação.
ALTER TABLE config_dev
    ADD COLUMN quantidade_caixas INT NOT NULL DEFAULT 1 AFTER marketplace_fee_percentual,
    ADD COLUMN caixas_compartilhados TINYINT(1) NOT NULL DEFAULT 1 AFTER quantidade_caixas;

-- Qual caixa físico (1, 2, 3...) cada sessão pertence. Com quantidade_caixas
-- = 1 esta coluna nunca sai de 1 -- é só relevante quando existe mais de um
-- caixa. numero_caixa_aberto é uma coluna gerada que só carrega valor
-- enquanto a sessão está com status = 'aberto' (fica NULL quando fechada);
-- a UNIQUE KEY nela garante, no próprio banco, que o mesmo número de caixa
-- nunca fique aberto duas vezes ao mesmo tempo -- MySQL permite múltiplos
-- NULL numa UNIQUE KEY, então caixas fechados nunca colidem entre si.
ALTER TABLE caixa_sessoes
    ADD COLUMN numero_caixa INT NOT NULL DEFAULT 1 AFTER id_caixa,
    ADD COLUMN numero_caixa_aberto INT GENERATED ALWAYS AS (IF(status = 'aberto', numero_caixa, NULL)) STORED,
    ADD UNIQUE KEY uniq_numero_caixa_aberto (numero_caixa_aberto);
