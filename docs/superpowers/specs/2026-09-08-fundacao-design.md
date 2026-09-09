# Sub-projeto 1: Fundação — Sistema Veronica (gestão de brechó)

Status: aprovado para virar plano de implementação
Data: 2026-09-08

## Contexto

O Sistema Veronica é um sistema de gestão para uma loja de brechó (roupas,
acessórios, eletrodomésticos, utensílios e móveis), com PDV físico, loja
online e uma linha de crédito para clientes comprarem a prazo. O projeto
inteiro foi decomposto em 4 sub-projetos, cada um com seu próprio ciclo
spec → plano → implementação:

1. **Fundação** (este documento)
2. PDV / Frente de Caixa
3. Loja Online / App do Cliente
4. Linha de Crédito

Este documento cobre apenas a Fundação: a base sobre a qual os outros três
sub-projetos serão construídos.

## Referência de código

O projeto `D:\Projetos clientes - CODERNEX\sys01` é usado como modelo de
código, padrão de conexão com banco e integração com Mercado Pago. Módulos e
arquivos relevantes já auditados:

- `conecta_bd.php` — conexão PDO, credenciais em `brechodaveve_config_credenciais.php` um
  nível acima da pasta pública
- `produtos/processa_upload_foto.php` — recorte/redimensionamento de foto via
  **GD nativo do PHP** (sem lib externa), salvando em `assets/img/produtos/`
- `caixa/ajax/gerar_pagamento_mp.php`, `os/ajax/marcar_pronta.php`,
  `loja/api/checkout_api.php` — padrão de cobrança Mercado Pago com
  `marketplace_fee` de 1% (`ceil($valor_total * 0.01 * 100) / 100`) e
  `sponsor_id: 194420711` (com fallback removendo esses campos se a API do MP
  rejeitar)
- `cancelar_vendas_reservadas.php` — mecanismo de expiração de reserva de
  carrinho/venda, reaproveitável para o timer de checkout da loja online

Diferente do sys01, o Sistema Veronica **não é multi-empresa** — não existe
`id_empresa` em nenhuma tabela. Não há testes automatizados neste projeto,
seguindo o padrão do sys01: validação é feita rodando o sistema de verdade.

## Escopo da Fundação

- Login com dois perfis: Admin (dona) e Funcionário
- Cadastro de clientes (WhatsApp obrigatório e único)
- Cadastro de categorias e variações de produto totalmente configuráveis pela
  dona (ex: Cor, Tamanho, Voltagem), com estoque e preço por combinação
- Upload de até 5 fotos por produto, processadas com GD e salvas em disco
- Configuração visual da loja (logo, cores)
- Configuração de formas de entrega (retirada na loja + entregas customizadas)

Fora de escopo aqui (entram nos próximos sub-projetos): PDV, loja online,
Mercado Pago, linha de crédito.

## Estrutura de pastas

```
/brechodaveve_config_credenciais.php  (fora da pasta pública, no nível acima)
/conecta_bd.php
/login.php
/sair.php
/clientes/
    novo.php, lista.php, detalhe.php
/produtos/
    categorias.php, variacoes.php, novo.php, lista.php, editar.php,
    ajax/upload_foto.php, ajax/deletar_foto.php, ajax/deletar_produto.php
/config_sistema/
    aparencia.php   (logo, cores)
    entrega.php     (formas de entrega)
/assets/img/produtos/{id_produto}/{ordem}.png
```

## Modelo de dados

```sql
usuarios
  id_usuario PK
  nome
  email UNIQUE
  senha_hash
  perfil ENUM('Admin','Funcionario')
  ativo TINYINT(1) DEFAULT 1

clientes
  id_cliente PK
  nome
  whatsapp VARCHAR(20) UNIQUE NOT NULL   -- só dígitos, com DDI+DDD
  email NULL
  endereco NULL
  criado_em

categorias
  id_categoria PK
  nome UNIQUE

variacoes
  id_variacao PK
  nome                                    -- ex: "Cor", "Tamanho", "Voltagem"

variacao_valores
  id_valor PK
  id_variacao FK -> variacoes
  valor                                   -- ex: "Azul", "M", "220V"

categoria_variacoes                       -- quais variações a dona ativou p/ cada categoria
  id_categoria FK -> categorias
  id_variacao FK -> variacoes
  PRIMARY KEY (id_categoria, id_variacao)

produtos
  id_produto PK
  nome
  descricao TEXT NULL
  id_categoria FK -> categorias
  condicao ENUM('novo','usado')
  preco_base DECIMAL(10,2)
  ativo TINYINT(1) DEFAULT 1
  criado_em

produto_variacoes                         -- 1 linha = 1 combinação vendível
  id_produto_variacao PK
  id_produto FK -> produtos ON DELETE CASCADE
  preco DECIMAL(10,2) NULL                -- NULL = herda preco_base
  estoque INT NOT NULL DEFAULT 0
  -- produto sem nenhuma variação ativada ganha 1 linha "padrão" automática
  -- (sem valores associados), unificando o controle de estoque em um único caminho

produto_variacao_valores                  -- valores que compõem uma combinação
  id_produto_variacao FK -> produto_variacoes ON DELETE CASCADE
  id_valor FK -> variacao_valores
  PRIMARY KEY (id_produto_variacao, id_valor)

produto_fotos
  id_foto PK
  id_produto FK -> produtos ON DELETE CASCADE
  ordem TINYINT                            -- 1 a 5
  caminho_arquivo

config_loja
  id_config PK (linha única)
  nome_loja
  logo_arquivo NULL
  cor_primaria
  cor_secundaria

formas_entrega
  id_entrega PK
  nome
  tipo ENUM('retirada','entrega')
  prazo_dias INT NULL
  custo DECIMAL(10,2) DEFAULT 0
  ativo TINYINT(1) DEFAULT 1
  fixa TINYINT(1) DEFAULT 0                -- "Retirar na loja" = 1, não pode ser deletada
```

**Decisão importante que afeta os próximos sub-projetos:** os itens de venda
(tabela `itens_venda`, que será criada no sub-projeto PDV) **não têm chave
estrangeira para `produtos`/`produto_variacoes`**. Cada item de venda grava um
retrato (nome, categoria, valores de variação, preço) no momento da venda.
Isso permite deletar fisicamente qualquer produto a qualquer momento —
inclusive um que já foi vendido — sem quebrar relatórios ou o histórico de
compras do cliente.

## Fluxos principais

**Cadastro de categoria + variações:**
1. Dona cria categoria (ex: "Roupas")
2. Dona cria/reaproveita variações da biblioteca (ex: "Cor", "Tamanho") e seus
   valores possíveis (ex: Cor → Azul, Vermelho; Tamanho → P, M, G)
3. Dona associa quais variações valem para aquela categoria

**Cadastro de produto:**
1. Escolhe categoria → sistema mostra as variações ativadas para ela
2. Ativa quais variações esse produto específico usa (pode não usar nenhuma)
3. Se usar variações, sistema gera as combinações (produto cartesiano dos
   valores escolhidos) e a dona preenche estoque/preço de cada uma
4. Se não ativar nenhuma variação, sistema cria 1 combinação padrão com o
   estoque/preço informado diretamente no produto
5. Upload de até 5 fotos (drag-and-drop ou seleção múltipla), processadas via
   GD (redimensionamento fixo, mesmo padrão do sys01) e salvas em
   `assets/img/produtos/{id_produto}/`

**Deleção de produto:**
1. Dona confirma exclusão (modal de confirmação)
2. Sistema apaga as linhas em `produto_fotos`, `produto_variacao_valores`,
   `produto_variacoes` e `produtos` (cascade)
3. Sistema apaga fisicamente os arquivos de foto do disco
4. Vendas antigas que referenciam esse produto continuam intactas (retrato
   gravado em `itens_venda`, sem FK)

## Tratamento de erros

- WhatsApp de cliente duplicado → erro amigável, não deixa cadastrar
- Upload de foto com formato não suportado (só jpeg/png/gif) → rejeita com
  mensagem clara, sem quebrar o restante do cadastro
- Exclusão de variação/valor de variação que está em uso por algum produto →
  bloqueada com aviso (não deleta, senão os produtos que a usam ficam
  inconsistentes)
- Falha ao apagar arquivo de foto do disco (permissão, arquivo já ausente
  etc.) → não interrompe a exclusão do produto no banco, mas registra aviso

## Testes

Sem suíte automatizada, seguindo o padrão do sys01. Validação manual: rodar o
sistema localmente e testar cadastro de categoria/variação, cadastro de
produto com e sem variação, upload/remoção de fotos (conferindo que o arquivo
some do disco), e exclusão de produto com e sem venda associada.
