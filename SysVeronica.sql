-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: sys-devclub_sysveronica:3306
-- Tempo de geração: 13/10/2026 às 04:31
-- Versão do servidor: 9.7.2
-- Versão do PHP: 8.2.27

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Banco de dados: `SysVeronica`
--

-- --------------------------------------------------------

--
-- Estrutura para tabela `caixa_sessoes`
--

CREATE TABLE `caixa_sessoes` (
  `id_caixa` int NOT NULL,
  `numero_caixa` int NOT NULL DEFAULT '1',
  `valor_inicial` decimal(10,2) NOT NULL,
  `aberto_por` int NOT NULL,
  `data_abertura` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('aberto','fechado') NOT NULL DEFAULT 'aberto',
  `fechado_por` int DEFAULT NULL,
  `data_fechamento` datetime DEFAULT NULL,
  `valor_final_informado` decimal(10,2) DEFAULT NULL,
  `valor_esperado` decimal(10,2) DEFAULT NULL,
  `diferenca` decimal(10,2) DEFAULT NULL,
  `observacao_fechamento` varchar(255) DEFAULT NULL,
  `numero_caixa_aberto` int GENERATED ALWAYS AS (if((`status` = _utf8mb4'aberto'),`numero_caixa`,NULL)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `caixa_terminais_point`
--

CREATE TABLE `caixa_terminais_point` (
  `numero_caixa` int NOT NULL,
  `terminal_id` varchar(150) NOT NULL,
  `atualizado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `categorias`
--

CREATE TABLE `categorias` (
  `id_categoria` int NOT NULL,
  `nome` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `categorias`
--

INSERT INTO `categorias` (`id_categoria`, `nome`) VALUES
(4, 'Acessórios'),
(3, 'Calçados'),
(2, 'Roupas'),
(9, 'Utilidades');

-- --------------------------------------------------------

--
-- Estrutura para tabela `categoria_variacoes`
--

CREATE TABLE `categoria_variacoes` (
  `id_categoria` int NOT NULL,
  `id_variacao` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `categoria_variacoes`
--

INSERT INTO `categoria_variacoes` (`id_categoria`, `id_variacao`) VALUES
(4, 1),
(4, 2);

-- --------------------------------------------------------

--
-- Estrutura para tabela `clientes`
--

CREATE TABLE `clientes` (
  `id_cliente` int NOT NULL,
  `nome` varchar(150) NOT NULL,
  `whatsapp` varchar(20) NOT NULL,
  `email` varchar(160) DEFAULT NULL,
  `endereco` varchar(255) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `senha_hash` varchar(255) DEFAULT NULL,
  `limite_credito` decimal(10,2) NOT NULL DEFAULT '0.00',
  `saldo_devedor` decimal(10,2) NOT NULL DEFAULT '0.00',
  `email_verificado_em` datetime DEFAULT NULL,
  `token_verificacao_email` varchar(64) DEFAULT NULL,
  `token_verificacao_expira_em` datetime DEFAULT NULL,
  `prazo_dias_credito` int NOT NULL DEFAULT '30',
  `whatsapp_verificado_em` datetime DEFAULT NULL,
  `token_verificacao_whatsapp` varchar(10) DEFAULT NULL,
  `token_verificacao_whatsapp_expira_em` datetime DEFAULT NULL,
  `whatsapp_ultimo_envio_em` datetime DEFAULT NULL,
  `excluido_em` datetime DEFAULT NULL,
  `excluido_por` int DEFAULT NULL,
  `login_whatsapp_bloqueado` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `clientes`
--

INSERT INTO `clientes` (`id_cliente`, `nome`, `whatsapp`, `email`, `endereco`, `criado_em`, `senha_hash`, `limite_credito`, `saldo_devedor`, `email_verificado_em`, `token_verificacao_email`, `token_verificacao_expira_em`, `prazo_dias_credito`, `whatsapp_verificado_em`, `token_verificacao_whatsapp`, `token_verificacao_whatsapp_expira_em`, `whatsapp_ultimo_envio_em`, `excluido_em`, `excluido_por`, `login_whatsapp_bloqueado`) VALUES
(12, 'Cliente teste', '5534900000000', NULL, NULL, '2026-10-10 04:08:05', '$2y$10$duFsyBG6bR0FQwd66qrfPuOTQkIlv0VCaxs1uMsacfF1aOKMvdvpS', 0.00, 0.00, NULL, NULL, NULL, 30, '2026-10-10 04:08:25', NULL, NULL, '2026-10-10 04:08:06', NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Estrutura para tabela `config_dev`
--

CREATE TABLE `config_dev` (
  `id_config` int NOT NULL,
  `nome_sistema` varchar(150) NOT NULL DEFAULT 'Sistema CoderNex',
  `mp_app_client_id` varchar(100) DEFAULT NULL,
  `mp_app_client_secret` varchar(100) DEFAULT NULL,
  `mp_webhook_secret` varchar(150) DEFAULT NULL,
  `marketplace_fee_percentual` decimal(5,2) NOT NULL DEFAULT '1.00',
  `quantidade_caixas` int NOT NULL DEFAULT '1',
  `caixas_compartilhados` tinyint(1) NOT NULL DEFAULT '1',
  `smtp_host` varchar(150) DEFAULT NULL,
  `smtp_port` int DEFAULT NULL,
  `smtp_user` varchar(150) DEFAULT NULL,
  `smtp_pass` varchar(255) DEFAULT NULL,
  `smtp_from_email` varchar(150) DEFAULT NULL,
  `smtp_from_name` varchar(150) DEFAULT NULL,
  `footer_nome` varchar(150) DEFAULT NULL,
  `footer_link` varchar(255) DEFAULT NULL,
  `travamento_ativo` enum('pagamento','manutencao') DEFAULT NULL,
  `travamento_pagamento_mensagem` text,
  `travamento_manutencao_mensagem` text,
  `whatsapp_verificacao_ativo` tinyint(1) NOT NULL DEFAULT '0',
  `evolution_base_url` varchar(255) DEFAULT NULL,
  `evolution_api_key` varchar(255) DEFAULT NULL,
  `evolution_instancia` varchar(150) DEFAULT NULL,
  `n8n_webhook_url` varchar(500) DEFAULT NULL,
  `superfrete_modo` enum('desativado','consulta','etiquetas') NOT NULL DEFAULT 'desativado',
  `superfrete_ambiente` enum('producao','sandbox') NOT NULL DEFAULT 'producao',
  `superfrete_token_dev` text
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `config_dev`
--

INSERT INTO `config_dev` (`id_config`, `nome_sistema`, `mp_app_client_id`, `mp_app_client_secret`, `mp_webhook_secret`, `marketplace_fee_percentual`, `quantidade_caixas`, `caixas_compartilhados`, `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass`, `smtp_from_email`, `smtp_from_name`, `footer_nome`, `footer_link`, `travamento_ativo`, `travamento_pagamento_mensagem`, `travamento_manutencao_mensagem`, `whatsapp_verificacao_ativo`, `evolution_base_url`, `evolution_api_key`, `evolution_instancia`, `n8n_webhook_url`, `superfrete_modo`, `superfrete_ambiente`, `superfrete_token_dev`) VALUES
(1, 'Sistema DevClub', '6009009700073000', 'T3ATTDTTrYOhZ8oTTDjaoCRCoEjQXSTT', '6c97a2e11c5fcf61b6674291784a662537a9f1a74', 1.00, 1, 1, 'email-ssl.com.br', 465, 'suporte@codernex.com.br', 'DoHCIm154SBxs', 'suporte@codernex.com.br', 'Sistema DevClub', 'Sistema DevClub', 'https://www.devclub.com.br/', 'manutencao', 'Existe um pagamento em aberto para o seu sistema. Entre em contato com o suporte para regularizar a situação.', 'O sistema está em manutenção no momento. Voltamos em breve.', 1, 'https://api.codernex.com.br/', '1989BB84A38C45E7972560', 'CoderNex-Automatico', 'https://n8n.codernex.com.br/webhook/verificacao', 'desativado', 'producao', 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpYXQiOjE3NjMwNTcyNDksInN1YiI6Im9zdDVHRUlMbXBkdkhITVFobGlFUnZXejNQODMifQ.U9bWTx6l3SrvdGxGT1LIdl0KWiCULS9mqUOeW605w6A');

-- --------------------------------------------------------

--
-- Estrutura para tabela `config_loja`
--

CREATE TABLE `config_loja` (
  `id_config` int NOT NULL,
  `nome_loja` varchar(150) NOT NULL DEFAULT 'Minha Loja',
  `logo_arquivo` varchar(255) DEFAULT NULL,
  `cor_primaria` char(7) NOT NULL DEFAULT '#8B5CF6',
  `cor_secundaria` char(7) NOT NULL DEFAULT '#F472B6',
  `prazo_reserva_minutos` int NOT NULL DEFAULT '15',
  `prazo_pagamento_minutos` int NOT NULL DEFAULT '10',
  `tema` enum('claro','escuro','personalizado') NOT NULL DEFAULT 'claro',
  `cor_fundo` char(7) NOT NULL DEFAULT '#FFFFFF',
  `cor_texto` char(7) NOT NULL DEFAULT '#1F2937',
  `whatsapp_loja` varchar(20) DEFAULT NULL,
  `endereco_loja` varchar(255) DEFAULT NULL,
  `horario_atendimento` varchar(255) DEFAULT NULL,
  `email_loja` varchar(160) DEFAULT NULL,
  `imprimir_automatico` tinyint(1) NOT NULL DEFAULT '0',
  `superfrete_token` text,
  `superfrete_cep_origem` char(8) DEFAULT NULL,
  `superfrete_servicos` varchar(30) NOT NULL DEFAULT '1,2',
  `superfrete_seguro` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `config_loja`
--

INSERT INTO `config_loja` (`id_config`, `nome_loja`, `logo_arquivo`, `cor_primaria`, `cor_secundaria`, `prazo_reserva_minutos`, `prazo_pagamento_minutos`, `tema`, `cor_fundo`, `cor_texto`, `whatsapp_loja`, `endereco_loja`, `horario_atendimento`, `email_loja`, `imprimir_automatico`, `superfrete_token`, `superfrete_cep_origem`, `superfrete_servicos`, `superfrete_seguro`) VALUES
(1, 'Loja MM', 'assets/img/logo/logo-1e78aab3.png', '#5d8703', '#7175f4', 5, 5, 'claro', '#0f657b', '#fafafa', '5531998989898', 'Pct Palmares 000', 'Segunda a sexta das 9h as 18h sábado das 9h as 11h', 'suportelojamm@gmail.com', 1, NULL, NULL, '1,2', 0);

-- --------------------------------------------------------

--
-- Estrutura para tabela `config_pagamento`
--

CREATE TABLE `config_pagamento` (
  `id_config` int NOT NULL,
  `mp_access_token` varchar(255) DEFAULT NULL,
  `mp_refresh_token` varchar(255) DEFAULT NULL,
  `mp_public_key` varchar(255) DEFAULT NULL,
  `mp_user_id` varchar(50) DEFAULT NULL,
  `mp_token_expira` datetime DEFAULT NULL,
  `mp_oauth_nonce` varchar(64) DEFAULT NULL,
  `mp_oauth_nonce_expira` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `config_pagamento`
--

INSERT INTO `config_pagamento` (`id_config`, `mp_access_token`, `mp_refresh_token`, `mp_public_key`, `mp_user_id`, `mp_token_expira`, `mp_oauth_nonce`, `mp_oauth_nonce_expira`) VALUES
(1, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `dev_usuarios`
--

CREATE TABLE `dev_usuarios` (
  `id_dev_usuario` int NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `nome` varchar(100) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `senha_recuperacao_hash` varchar(255) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tentativas_falhas` int NOT NULL DEFAULT '0',
  `ciclos_bloqueio` int NOT NULL DEFAULT '0',
  `bloqueado_ate` datetime DEFAULT NULL,
  `bloqueio_seguranca` tinyint(1) NOT NULL DEFAULT '0',
  `token_redefinicao_senha` varchar(64) DEFAULT NULL,
  `token_redefinicao_expira_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `dev_usuarios`
--

INSERT INTO `dev_usuarios` (`id_dev_usuario`, `usuario`, `nome`, `email`, `senha_hash`, `senha_recuperacao_hash`, `criado_em`, `tentativas_falhas`, `ciclos_bloqueio`, `bloqueado_ate`, `bloqueio_seguranca`, `token_redefinicao_senha`, `token_redefinicao_expira_em`) VALUES
(1, 'DevMaster', 'Adriano Dev', 'telefoneadriano@gmail.com', '$2y$10$AF4Vqyi2gd6o1NrfMiLXNO.n59PWYOjNAKkCJGXiMFvlDg3RH6Ww.', '$2y$10$K3W5dIMA7pZwgZt8g8Mc.OAThQx.bwrAZocKz3iV3TQGeoun.1mle', '2026-09-15 14:55:14', 0, 0, NULL, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `favoritos`
--

CREATE TABLE `favoritos` (
  `id_favorito` int NOT NULL,
  `id_cliente` int NOT NULL,
  `id_produto` int NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `fiscal_config`
--

CREATE TABLE `fiscal_config` (
  `id_config` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `ativo` tinyint(1) NOT NULL DEFAULT '0',
  `token` varchar(300) DEFAULT NULL,
  `ambiente` enum('homologacao','producao') NOT NULL DEFAULT 'homologacao',
  `conectado_em` datetime DEFAULT NULL,
  `emite_nfce` tinyint(1) NOT NULL DEFAULT '1',
  `emite_nfe` tinyint(1) NOT NULL DEFAULT '1',
  `aceite_em` datetime DEFAULT NULL,
  `aceite_por` int DEFAULT NULL,
  `uf_loja` char(2) DEFAULT NULL,
  `cfop_padrao` char(4) NOT NULL DEFAULT '5102',
  `origem_padrao` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `unidade_padrao` varchar(6) NOT NULL DEFAULT 'UN',
  `cod_tributacao_padrao` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `fiscal_config`
--

INSERT INTO `fiscal_config` (`id_config`, `ativo`, `token`, `ambiente`, `conectado_em`, `emite_nfce`, `emite_nfe`, `aceite_em`, `aceite_por`, `uf_loja`, `cfop_padrao`, `origem_padrao`, `unidade_padrao`, `cod_tributacao_padrao`) VALUES
(1, 1, NULL, 'homologacao', NULL, 1, 1, '2026-10-10 04:13:04', 1, 'MG', '5102', 3, 'UN', NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `formas_entrega`
--

CREATE TABLE `formas_entrega` (
  `id_entrega` int NOT NULL,
  `nome` varchar(100) NOT NULL,
  `tipo` enum('retirada','entrega') NOT NULL,
  `prazo_dias` int DEFAULT NULL,
  `custo` decimal(10,2) NOT NULL DEFAULT '0.00',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `fixa` tinyint(1) NOT NULL DEFAULT '0',
  `superfrete` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `formas_entrega`
--

INSERT INTO `formas_entrega` (`id_entrega`, `nome`, `tipo`, `prazo_dias`, `custo`, `ativo`, `fixa`, `superfrete`) VALUES
(1, 'Retirar na loja', 'retirada', 5, 0.00, 1, 1, 0),
(2, 'Moto Boy', 'entrega', 1, 15.00, 1, 0, 0),
(3, 'Uber Entregas', 'entrega', 1, 25.00, 1, 0, 0),
(4, 'SuperFrete', 'entrega', NULL, 0.00, 1, 0, 1);

-- --------------------------------------------------------

--
-- Estrutura para tabela `itens_venda`
--

CREATE TABLE `itens_venda` (
  `id_item` int NOT NULL,
  `id_venda` int NOT NULL,
  `nome_produto` varchar(150) NOT NULL,
  `descricao_combinacao` varchar(255) DEFAULT NULL,
  `id_produto_variacao` int DEFAULT NULL,
  `quantidade` int NOT NULL,
  `preco_unit` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `movimentos_credito`
--

CREATE TABLE `movimentos_credito` (
  `id_movimento` int NOT NULL,
  `id_cliente` int NOT NULL,
  `tipo` enum('compra','pagamento') NOT NULL,
  `status` enum('Confirmado','Pendente','Cancelado') NOT NULL DEFAULT 'Confirmado',
  `valor` decimal(10,2) NOT NULL,
  `id_venda` int DEFAULT NULL,
  `forma_pagamento` varchar(50) DEFAULT NULL,
  `id_pagamento_mp` varchar(50) DEFAULT NULL,
  `criado_por` int DEFAULT NULL,
  `data_movimento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `id_caixa` int DEFAULT NULL,
  `observacao` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `notas_fiscais`
--

CREATE TABLE `notas_fiscais` (
  `id_nota` int NOT NULL,
  `id_venda` int NOT NULL,
  `tipo` enum('nfce','nfe') NOT NULL,
  `ambiente` enum('homologacao','producao') NOT NULL,
  `status` enum('processando','autorizada','rejeitada','cancelada') NOT NULL DEFAULT 'processando',
  `identificador` varchar(80) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `destinatario_doc` varchar(14) DEFAULT NULL,
  `destinatario_nome` varchar(140) DEFAULT NULL,
  `numero` varchar(30) DEFAULT NULL,
  `serie` varchar(10) DEFAULT NULL,
  `chave` varchar(60) DEFAULT NULL,
  `protocolo` varchar(60) DEFAULT NULL,
  `arquivo_base64` mediumtext,
  `arquivo_tipo` varchar(8) DEFAULT NULL,
  `xml_base64` mediumtext,
  `erro` text,
  `itens_json` mediumtext,
  `token_publico` char(32) NOT NULL,
  `emitida_por` varchar(120) NOT NULL,
  `autorizada_em` datetime DEFAULT NULL,
  `cancelada_em` datetime DEFAULT NULL,
  `cancelada_por` varchar(120) DEFAULT NULL,
  `motivo_cancelamento` varchar(255) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `produtos`
--

CREATE TABLE `produtos` (
  `id_produto` int NOT NULL,
  `nome` varchar(150) NOT NULL,
  `descricao` text,
  `id_categoria` int NOT NULL,
  `condicao` enum('novo','usado') NOT NULL DEFAULT 'usado',
  `preco_base` decimal(10,2) NOT NULL,
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `codigo` varchar(30) DEFAULT NULL,
  `estoque_gerenciado` tinyint(1) NOT NULL DEFAULT '1',
  `peso_gramas` int DEFAULT NULL,
  `caixa_tamanho` tinyint DEFAULT NULL,
  `fiscal_ncm` char(8) DEFAULT NULL,
  `fiscal_cfop` char(4) DEFAULT NULL,
  `fiscal_origem` tinyint UNSIGNED DEFAULT NULL,
  `fiscal_unidade` varchar(6) DEFAULT NULL,
  `fiscal_cod_tributacao` varchar(40) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `produtos`
--

INSERT INTO `produtos` (`id_produto`, `nome`, `descricao`, `id_categoria`, `condicao`, `preco_base`, `ativo`, `criado_em`, `codigo`, `estoque_gerenciado`, `peso_gramas`, `caixa_tamanho`, `fiscal_ncm`, `fiscal_cfop`, `fiscal_origem`, `fiscal_unidade`, `fiscal_cod_tributacao`) VALUES
(9007, 'Blusa de Tricô Bege', 'Abrace o conforto sem abrir mão da elegância com a nossa Blusa de Tricô Bege. Confeccionada com uma trama rica e macia, este suéter é a peça essencial para te aquecer nos dias mais frios com muito estilo. Seu tom neutro e design atemporal oferecem versatilidade máxima, permitindo criar looks incríveis tanto para o ambiente de trabalho quanto para um café no fim de semana. Sinta o aconchego que você merece com um caimento perfeito.\r\n\r\nDetalhes do Produto:\r\n\r\nCor: Bege clássico, um tom neutro que combina facilmente com jeans, alfaiataria ou saias.\r\n\r\nModelagem: Soltinha e confortável, garantindo liberdade de movimento.\r\n\r\nAcabamentos: Gola redonda (careca), com punhos e barra canelados que ajudam a reter o calor e oferecem melhor ajuste.\r\n\r\nTextura: Ponto de tricô trabalhado, adicionando charme e um toque visual sofisticado à peça.\r\n\r\nEstilo: Casual e versátil. Fica perfeita usada sozinha ou em sobreposições com camisas de gola.\r\n\r\nUso Ideal: Dias de outono/inverno, trabalho, viagens e passeios casuais.', 2, 'usado', 29.00, 1, '2026-09-12 10:04:37', '906', 0, 300, 2, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Estrutura para tabela `produto_fotos`
--

CREATE TABLE `produto_fotos` (
  `id_foto` int NOT NULL,
  `id_produto` int NOT NULL,
  `ordem` tinyint NOT NULL,
  `caminho_arquivo` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `produto_fotos`
--

INSERT INTO `produto_fotos` (`id_foto`, `id_produto`, `ordem`, `caminho_arquivo`) VALUES
(205, 9007, 1, 'assets/img/produtos/9007/1.jpg');

-- --------------------------------------------------------

--
-- Estrutura para tabela `produto_variacao_valores`
--

CREATE TABLE `produto_variacao_valores` (
  `id_produto_variacao` int NOT NULL,
  `id_valor` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `produto_variacoes`
--

CREATE TABLE `produto_variacoes` (
  `id_produto_variacao` int NOT NULL,
  `id_produto` int NOT NULL,
  `preco` decimal(10,2) DEFAULT NULL,
  `estoque` int NOT NULL DEFAULT '0',
  `estoque_reservado` int NOT NULL DEFAULT '0',
  `liberado_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `produto_variacoes`
--

INSERT INTO `produto_variacoes` (`id_produto_variacao`, `id_produto`, `preco`, `estoque`, `estoque_reservado`, `liberado_em`) VALUES
(9007, 9007, NULL, 0, 0, '2026-09-16 16:34:09');

-- --------------------------------------------------------

--
-- Estrutura para tabela `solicitacoes_credito`
--

CREATE TABLE `solicitacoes_credito` (
  `id_solicitacao` int NOT NULL,
  `id_cliente` int NOT NULL,
  `valor_solicitado` decimal(10,2) NOT NULL,
  `status` enum('Pendente','Aprovada','Rejeitada') NOT NULL DEFAULT 'Pendente',
  `valor_aprovado` decimal(10,2) DEFAULT NULL,
  `observacao_admin` varchar(255) DEFAULT NULL,
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `respondido_em` datetime DEFAULT NULL,
  `respondido_por` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `usuarios`
--

CREATE TABLE `usuarios` (
  `id_usuario` int NOT NULL,
  `nome` varchar(120) NOT NULL,
  `email` varchar(160) NOT NULL,
  `senha_hash` varchar(255) NOT NULL,
  `perfil` enum('Admin','Funcionario') NOT NULL DEFAULT 'Funcionario',
  `ativo` tinyint(1) NOT NULL DEFAULT '1',
  `criado_em` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `usuarios`
--

INSERT INTO `usuarios` (`id_usuario`, `nome`, `email`, `senha_hash`, `perfil`, `ativo`, `criado_em`) VALUES
(1, 'Nome Cliente', 'sistema@gmail.com', '$2y$10$kwm3N.npHMvQNGCCnHfeSO8HhYLnZFemn9Dp06E2ohjeNk/q4snWe', 'Admin', 1, '2026-09-09 09:23:00');

-- --------------------------------------------------------

--
-- Estrutura para tabela `variacao_valores`
--

CREATE TABLE `variacao_valores` (
  `id_valor` int NOT NULL,
  `id_variacao` int NOT NULL,
  `valor` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `variacao_valores`
--

INSERT INTO `variacao_valores` (`id_valor`, `id_variacao`, `valor`) VALUES
(5, 1, 'Amarero'),
(1, 1, 'Azul'),
(17, 1, 'Bege'),
(8, 1, 'Branco'),
(7, 1, 'Cinza'),
(6, 1, 'Marrom'),
(3, 1, 'Preto'),
(4, 1, 'Rosa'),
(9, 1, 'Verde'),
(2, 1, 'Vermelho'),
(14, 2, 'Curto'),
(12, 2, 'G'),
(13, 2, 'GG'),
(16, 2, 'Longo'),
(11, 2, 'M'),
(15, 2, 'Médio'),
(10, 2, 'P');

-- --------------------------------------------------------

--
-- Estrutura para tabela `variacoes`
--

CREATE TABLE `variacoes` (
  `id_variacao` int NOT NULL,
  `nome` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `variacoes`
--

INSERT INTO `variacoes` (`id_variacao`, `nome`) VALUES
(1, 'Cor'),
(2, 'Tamanho');

-- --------------------------------------------------------

--
-- Estrutura para tabela `vendas`
--

CREATE TABLE `vendas` (
  `id_venda` int NOT NULL,
  `id_caixa` int DEFAULT NULL,
  `id_cliente` int DEFAULT NULL,
  `id_usuario` int DEFAULT NULL,
  `valor_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('Reservado','Pago','Cancelado') NOT NULL DEFAULT 'Reservado',
  `status_entrega` enum('Aguardando preparo','Preparando','Pronto','Enviado','Entregue') DEFAULT NULL,
  `forma_pagamento` varchar(50) DEFAULT NULL,
  `id_pagamento_mp` varchar(50) DEFAULT NULL,
  `id_order_mp` varchar(60) DEFAULT NULL,
  `data_venda` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pagamento_expira_em` datetime DEFAULT NULL,
  `link_pagamento_mp` varchar(500) DEFAULT NULL,
  `origem` enum('pdv','loja') NOT NULL DEFAULT 'pdv',
  `id_entrega` int DEFAULT NULL,
  `motivo_cancelamento` text,
  `token_recibo` varchar(64) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `vendas_envio`
--

CREATE TABLE `vendas_envio` (
  `id_venda` int NOT NULL,
  `servico_id` int DEFAULT NULL,
  `servico_nome` varchar(60) DEFAULT NULL,
  `prazo_dias` int DEFAULT NULL,
  `valor_frete` decimal(10,2) NOT NULL DEFAULT '0.00',
  `destino_nome` varchar(150) DEFAULT NULL,
  `destino_documento` varchar(14) DEFAULT NULL,
  `destino_telefone` varchar(15) DEFAULT NULL,
  `destino_email` varchar(150) DEFAULT NULL,
  `destino_cep` char(8) DEFAULT NULL,
  `destino_endereco` varchar(200) DEFAULT NULL,
  `destino_numero` varchar(20) DEFAULT NULL,
  `destino_complemento` varchar(100) DEFAULT NULL,
  `destino_bairro` varchar(100) DEFAULT NULL,
  `destino_cidade` varchar(100) DEFAULT NULL,
  `destino_uf` char(2) DEFAULT NULL,
  `volume_json` text,
  `superfrete_order_id` varchar(60) DEFAULT NULL,
  `superfrete_status` varchar(30) DEFAULT NULL,
  `superfrete_rastreio` varchar(60) DEFAULT NULL,
  `superfrete_etiqueta_url` varchar(500) DEFAULT NULL,
  `superfrete_valor_etiqueta` decimal(10,2) DEFAULT NULL,
  `nota_numero` varchar(20) DEFAULT NULL,
  `nota_chave` char(44) DEFAULT NULL,
  `etiqueta_gerada_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `venda_pagamentos`
--

CREATE TABLE `venda_pagamentos` (
  `id_pagamento` int NOT NULL,
  `id_venda` int NOT NULL,
  `forma_pagamento` varchar(50) NOT NULL,
  `valor` decimal(10,2) NOT NULL,
  `data_pagamento` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Estrutura para tabela `whatsapp_conexao`
--

CREATE TABLE `whatsapp_conexao` (
  `id` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `instancia` varchar(80) DEFAULT NULL,
  `estado` enum('desconectado','conectando','conectado') NOT NULL DEFAULT 'desconectado',
  `numero` varchar(20) DEFAULT NULL,
  `aceite_em` datetime DEFAULT NULL,
  `aceite_por` int DEFAULT NULL,
  `conectado_em` datetime DEFAULT NULL,
  `verificado_em` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Despejando dados para a tabela `whatsapp_conexao`
--

INSERT INTO `whatsapp_conexao` (`id`, `instancia`, `estado`, `numero`, `aceite_em`, `aceite_por`, `conectado_em`, `verificado_em`) VALUES
(1, 'codernex-sistema-loja25-tolici-easypanel-host', 'desconectado', NULL, '2026-10-10 04:06:09', 1, NULL, '2026-10-10 13:26:01');

--
-- Índices para tabelas despejadas
--

--
-- Índices de tabela `caixa_sessoes`
--
ALTER TABLE `caixa_sessoes`
  ADD PRIMARY KEY (`id_caixa`),
  ADD UNIQUE KEY `uniq_numero_caixa_aberto` (`numero_caixa_aberto`),
  ADD KEY `aberto_por` (`aberto_por`),
  ADD KEY `fechado_por` (`fechado_por`);

--
-- Índices de tabela `caixa_terminais_point`
--
ALTER TABLE `caixa_terminais_point`
  ADD PRIMARY KEY (`numero_caixa`);

--
-- Índices de tabela `categorias`
--
ALTER TABLE `categorias`
  ADD PRIMARY KEY (`id_categoria`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Índices de tabela `categoria_variacoes`
--
ALTER TABLE `categoria_variacoes`
  ADD PRIMARY KEY (`id_categoria`,`id_variacao`),
  ADD KEY `id_variacao` (`id_variacao`);

--
-- Índices de tabela `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id_cliente`),
  ADD UNIQUE KEY `whatsapp` (`whatsapp`),
  ADD UNIQUE KEY `uniq_clientes_email` (`email`),
  ADD UNIQUE KEY `uniq_clientes_token_verificacao` (`token_verificacao_email`),
  ADD KEY `idx_clientes_excluido_em` (`excluido_em`);

--
-- Índices de tabela `config_dev`
--
ALTER TABLE `config_dev`
  ADD PRIMARY KEY (`id_config`);

--
-- Índices de tabela `config_loja`
--
ALTER TABLE `config_loja`
  ADD PRIMARY KEY (`id_config`);

--
-- Índices de tabela `config_pagamento`
--
ALTER TABLE `config_pagamento`
  ADD PRIMARY KEY (`id_config`);

--
-- Índices de tabela `dev_usuarios`
--
ALTER TABLE `dev_usuarios`
  ADD PRIMARY KEY (`id_dev_usuario`),
  ADD UNIQUE KEY `usuario` (`usuario`);

--
-- Índices de tabela `favoritos`
--
ALTER TABLE `favoritos`
  ADD PRIMARY KEY (`id_favorito`),
  ADD UNIQUE KEY `uniq_cliente_produto` (`id_cliente`,`id_produto`),
  ADD KEY `id_produto` (`id_produto`);

--
-- Índices de tabela `fiscal_config`
--
ALTER TABLE `fiscal_config`
  ADD PRIMARY KEY (`id_config`);

--
-- Índices de tabela `formas_entrega`
--
ALTER TABLE `formas_entrega`
  ADD PRIMARY KEY (`id_entrega`);

--
-- Índices de tabela `itens_venda`
--
ALTER TABLE `itens_venda`
  ADD PRIMARY KEY (`id_item`),
  ADD KEY `id_venda` (`id_venda`);

--
-- Índices de tabela `movimentos_credito`
--
ALTER TABLE `movimentos_credito`
  ADD PRIMARY KEY (`id_movimento`),
  ADD KEY `id_cliente` (`id_cliente`),
  ADD KEY `id_venda` (`id_venda`),
  ADD KEY `criado_por` (`criado_por`),
  ADD KEY `id_caixa` (`id_caixa`);

--
-- Índices de tabela `notas_fiscais`
--
ALTER TABLE `notas_fiscais`
  ADD PRIMARY KEY (`id_nota`),
  ADD UNIQUE KEY `uniq_nota_identificador` (`identificador`),
  ADD UNIQUE KEY `uniq_nota_token` (`token_publico`),
  ADD KEY `idx_nota_venda` (`id_venda`),
  ADD KEY `idx_nota_pendente` (`status`,`criado_em`);

--
-- Índices de tabela `produtos`
--
ALTER TABLE `produtos`
  ADD PRIMARY KEY (`id_produto`),
  ADD UNIQUE KEY `uniq_produtos_codigo` (`codigo`),
  ADD KEY `id_categoria` (`id_categoria`);

--
-- Índices de tabela `produto_fotos`
--
ALTER TABLE `produto_fotos`
  ADD PRIMARY KEY (`id_foto`),
  ADD UNIQUE KEY `uniq_ordem_por_produto` (`id_produto`,`ordem`);

--
-- Índices de tabela `produto_variacao_valores`
--
ALTER TABLE `produto_variacao_valores`
  ADD PRIMARY KEY (`id_produto_variacao`,`id_valor`),
  ADD KEY `id_valor` (`id_valor`);

--
-- Índices de tabela `produto_variacoes`
--
ALTER TABLE `produto_variacoes`
  ADD PRIMARY KEY (`id_produto_variacao`),
  ADD KEY `id_produto` (`id_produto`);

--
-- Índices de tabela `solicitacoes_credito`
--
ALTER TABLE `solicitacoes_credito`
  ADD PRIMARY KEY (`id_solicitacao`),
  ADD KEY `id_cliente` (`id_cliente`),
  ADD KEY `respondido_por` (`respondido_por`);

--
-- Índices de tabela `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Índices de tabela `variacao_valores`
--
ALTER TABLE `variacao_valores`
  ADD PRIMARY KEY (`id_valor`),
  ADD UNIQUE KEY `uniq_valor_por_variacao` (`id_variacao`,`valor`);

--
-- Índices de tabela `variacoes`
--
ALTER TABLE `variacoes`
  ADD PRIMARY KEY (`id_variacao`),
  ADD UNIQUE KEY `nome` (`nome`);

--
-- Índices de tabela `vendas`
--
ALTER TABLE `vendas`
  ADD PRIMARY KEY (`id_venda`),
  ADD UNIQUE KEY `uniq_vendas_token_recibo` (`token_recibo`),
  ADD KEY `id_caixa` (`id_caixa`),
  ADD KEY `id_cliente` (`id_cliente`),
  ADD KEY `id_usuario` (`id_usuario`),
  ADD KEY `id_entrega` (`id_entrega`),
  ADD KEY `idx_loja_expira` (`origem`,`status`,`data_venda`);

--
-- Índices de tabela `vendas_envio`
--
ALTER TABLE `vendas_envio`
  ADD PRIMARY KEY (`id_venda`);

--
-- Índices de tabela `venda_pagamentos`
--
ALTER TABLE `venda_pagamentos`
  ADD PRIMARY KEY (`id_pagamento`),
  ADD KEY `id_venda` (`id_venda`);

--
-- Índices de tabela `whatsapp_conexao`
--
ALTER TABLE `whatsapp_conexao`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT para tabelas despejadas
--

--
-- AUTO_INCREMENT de tabela `caixa_sessoes`
--
ALTER TABLE `caixa_sessoes`
  MODIFY `id_caixa` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `categorias`
--
ALTER TABLE `categorias`
  MODIFY `id_categoria` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de tabela `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id_cliente` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de tabela `dev_usuarios`
--
ALTER TABLE `dev_usuarios`
  MODIFY `id_dev_usuario` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de tabela `favoritos`
--
ALTER TABLE `favoritos`
  MODIFY `id_favorito` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT de tabela `formas_entrega`
--
ALTER TABLE `formas_entrega`
  MODIFY `id_entrega` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de tabela `itens_venda`
--
ALTER TABLE `itens_venda`
  MODIFY `id_item` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=146;

--
-- AUTO_INCREMENT de tabela `movimentos_credito`
--
ALTER TABLE `movimentos_credito`
  MODIFY `id_movimento` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT de tabela `notas_fiscais`
--
ALTER TABLE `notas_fiscais`
  MODIFY `id_nota` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de tabela `produtos`
--
ALTER TABLE `produtos`
  MODIFY `id_produto` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9025;

--
-- AUTO_INCREMENT de tabela `produto_fotos`
--
ALTER TABLE `produto_fotos`
  MODIFY `id_foto` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=206;

--
-- AUTO_INCREMENT de tabela `produto_variacoes`
--
ALTER TABLE `produto_variacoes`
  MODIFY `id_produto_variacao` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9061;

--
-- AUTO_INCREMENT de tabela `solicitacoes_credito`
--
ALTER TABLE `solicitacoes_credito`
  MODIFY `id_solicitacao` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id_usuario` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de tabela `variacao_valores`
--
ALTER TABLE `variacao_valores`
  MODIFY `id_valor` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT de tabela `variacoes`
--
ALTER TABLE `variacoes`
  MODIFY `id_variacao` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de tabela `vendas`
--
ALTER TABLE `vendas`
  MODIFY `id_venda` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=103;

--
-- AUTO_INCREMENT de tabela `venda_pagamentos`
--
ALTER TABLE `venda_pagamentos`
  MODIFY `id_pagamento` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- Restrições para tabelas despejadas
--

--
-- Restrições para tabelas `caixa_sessoes`
--
ALTER TABLE `caixa_sessoes`
  ADD CONSTRAINT `caixa_sessoes_ibfk_1` FOREIGN KEY (`aberto_por`) REFERENCES `usuarios` (`id_usuario`),
  ADD CONSTRAINT `caixa_sessoes_ibfk_2` FOREIGN KEY (`fechado_por`) REFERENCES `usuarios` (`id_usuario`);

--
-- Restrições para tabelas `categoria_variacoes`
--
ALTER TABLE `categoria_variacoes`
  ADD CONSTRAINT `categoria_variacoes_ibfk_1` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON DELETE CASCADE,
  ADD CONSTRAINT `categoria_variacoes_ibfk_2` FOREIGN KEY (`id_variacao`) REFERENCES `variacoes` (`id_variacao`) ON DELETE CASCADE;

--
-- Restrições para tabelas `favoritos`
--
ALTER TABLE `favoritos`
  ADD CONSTRAINT `favoritos_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`),
  ADD CONSTRAINT `favoritos_ibfk_2` FOREIGN KEY (`id_produto`) REFERENCES `produtos` (`id_produto`) ON DELETE CASCADE;

--
-- Restrições para tabelas `itens_venda`
--
ALTER TABLE `itens_venda`
  ADD CONSTRAINT `itens_venda_ibfk_1` FOREIGN KEY (`id_venda`) REFERENCES `vendas` (`id_venda`) ON DELETE CASCADE;

--
-- Restrições para tabelas `movimentos_credito`
--
ALTER TABLE `movimentos_credito`
  ADD CONSTRAINT `movimentos_credito_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`),
  ADD CONSTRAINT `movimentos_credito_ibfk_2` FOREIGN KEY (`id_venda`) REFERENCES `vendas` (`id_venda`),
  ADD CONSTRAINT `movimentos_credito_ibfk_3` FOREIGN KEY (`criado_por`) REFERENCES `usuarios` (`id_usuario`),
  ADD CONSTRAINT `movimentos_credito_ibfk_4` FOREIGN KEY (`id_caixa`) REFERENCES `caixa_sessoes` (`id_caixa`);

--
-- Restrições para tabelas `notas_fiscais`
--
ALTER TABLE `notas_fiscais`
  ADD CONSTRAINT `notas_fiscais_ibfk_1` FOREIGN KEY (`id_venda`) REFERENCES `vendas` (`id_venda`);

--
-- Restrições para tabelas `produtos`
--
ALTER TABLE `produtos`
  ADD CONSTRAINT `produtos_ibfk_1` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`);

--
-- Restrições para tabelas `produto_fotos`
--
ALTER TABLE `produto_fotos`
  ADD CONSTRAINT `produto_fotos_ibfk_1` FOREIGN KEY (`id_produto`) REFERENCES `produtos` (`id_produto`) ON DELETE CASCADE;

--
-- Restrições para tabelas `produto_variacao_valores`
--
ALTER TABLE `produto_variacao_valores`
  ADD CONSTRAINT `produto_variacao_valores_ibfk_1` FOREIGN KEY (`id_produto_variacao`) REFERENCES `produto_variacoes` (`id_produto_variacao`) ON DELETE CASCADE,
  ADD CONSTRAINT `produto_variacao_valores_ibfk_2` FOREIGN KEY (`id_valor`) REFERENCES `variacao_valores` (`id_valor`);

--
-- Restrições para tabelas `produto_variacoes`
--
ALTER TABLE `produto_variacoes`
  ADD CONSTRAINT `produto_variacoes_ibfk_1` FOREIGN KEY (`id_produto`) REFERENCES `produtos` (`id_produto`) ON DELETE CASCADE;

--
-- Restrições para tabelas `solicitacoes_credito`
--
ALTER TABLE `solicitacoes_credito`
  ADD CONSTRAINT `solicitacoes_credito_ibfk_1` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`),
  ADD CONSTRAINT `solicitacoes_credito_ibfk_2` FOREIGN KEY (`respondido_por`) REFERENCES `usuarios` (`id_usuario`);

--
-- Restrições para tabelas `variacao_valores`
--
ALTER TABLE `variacao_valores`
  ADD CONSTRAINT `variacao_valores_ibfk_1` FOREIGN KEY (`id_variacao`) REFERENCES `variacoes` (`id_variacao`) ON DELETE CASCADE;

--
-- Restrições para tabelas `vendas`
--
ALTER TABLE `vendas`
  ADD CONSTRAINT `vendas_ibfk_1` FOREIGN KEY (`id_caixa`) REFERENCES `caixa_sessoes` (`id_caixa`),
  ADD CONSTRAINT `vendas_ibfk_2` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`),
  ADD CONSTRAINT `vendas_ibfk_3` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
  ADD CONSTRAINT `vendas_ibfk_4` FOREIGN KEY (`id_entrega`) REFERENCES `formas_entrega` (`id_entrega`);

--
-- Restrições para tabelas `vendas_envio`
--
ALTER TABLE `vendas_envio`
  ADD CONSTRAINT `vendas_envio_ibfk_1` FOREIGN KEY (`id_venda`) REFERENCES `vendas` (`id_venda`) ON DELETE CASCADE;

--
-- Restrições para tabelas `venda_pagamentos`
--
ALTER TABLE `venda_pagamentos`
  ADD CONSTRAINT `venda_pagamentos_ibfk_1` FOREIGN KEY (`id_venda`) REFERENCES `vendas` (`id_venda`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
