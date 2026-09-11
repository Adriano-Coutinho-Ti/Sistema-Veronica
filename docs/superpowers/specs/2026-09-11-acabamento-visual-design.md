# Acabamento Visual — Sistema Veronica

Status: aprovado para virar plano de implementação
Data: 2026-09-11

## Contexto

Os 4 sub-projetos funcionais do Sistema Veronica (Fundação, PDV, Loja Online,
Linha de Crédito) estão completos, revisados e em produção — mas
deliberadamente sem nenhum CSS/design: HTML puro, sem layout pensado, sem
navegação entre telas. Esta etapa ("acabamento") aplica identidade visual e
navegação em todo o sistema, sem alterar nenhuma regra de negócio já
implementada.

**Requisito não-negociável: responsivo mobile.** O foco do CSS é nunca
quebrar nem ficar feio em celular — mobile-first em tudo, testado
explicitamente em viewport de celular antes de qualquer página ser
considerada pronta.

## Sistema de tema

`config_loja` (Fundação) ganha:

```sql
ALTER TABLE config_loja
    ADD COLUMN tema ENUM('claro', 'escuro', 'personalizado') NOT NULL DEFAULT 'claro',
    ADD COLUMN cor_fundo CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    ADD COLUMN cor_texto CHAR(7) NOT NULL DEFAULT '#1F2937';
```

Junto com `cor_primaria`/`cor_secundaria` (já existem), isso dá as 4
cores-chave do modo `personalizado`. Um novo `includes/tema.php` lê
`config_loja` e imprime um bloco `<style>` com variáveis CSS
(`--cor-primaria`, `--cor-secundaria`, `--cor-fundo`, `--cor-texto`, mais
variáveis derivadas como `--cor-texto-secundario`/`--cor-borda` calculadas
a partir das 4 principais):

- `tema = 'claro'`: paleta clara fixa, pré-definida no próprio
  `includes/tema.php` (ignora as 4 cores do banco).
- `tema = 'escuro'`: paleta escura fixa, pré-definida (ignora as 4 cores do
  banco).
- `tema = 'personalizado'`: usa as 4 cores salvas em `config_loja`.

**A aparência (tema/logo) vale só para a Loja Online** (catálogo, produto,
carrinho, checkout, cadastro/login, meus débitos, status do pedido). As
telas internas (Fundação, PDV, Linha de Crédito) usam um visual neutro e
fixo — são ferramentas de trabalho da equipe, não vitrine da loja.

## CSS

Duas folhas de estilo novas, ambas mobile-first (estilo base pensado pra
tela pequena; `@media (min-width: 768px)` acrescenta/ajusta pra telas
maiores — nunca o contrário):

- `assets/css/loja.css`: usa as variáveis de `includes/tema.php`, estiliza
  catálogo, grade de produtos, página de produto, carrinho, checkout,
  formulários de login/cadastro, extrato de débitos.
- `assets/css/admin.css`: paleta neutra fixa (sem variáveis de tema),
  estiliza tabelas, formulários, botões das telas internas.

Regras obrigatórias em ambas: nenhuma largura fixa que estoure a viewport,
`img { max-width: 100%; height: auto; }`, tabelas com `overflow-x: auto`
num contêiner (nunca a página inteira rolando na horizontal), botões e
campos de formulário com área de toque confortável (mínimo ~44px de
altura) em telas pequenas.

## Navegação

Dois cabeçalhos compartilhados (includes PHP, não componentes de
framework):

- `includes/loja_header.php`: logo (ou nome da loja se não houver logo),
  e o menu do cliente — Catálogo, Carrinho, Meus Débitos, e
  Entrar/Cadastrar ou Sair conforme o estado de login. Vira uma barra que
  quebra/empilha em telas pequenas (`flex-wrap`), sem precisar de
  JavaScript.
- `includes/admin_header.php`: menu interno — Produtos, Categorias,
  Clientes, Caixa, Linha de Crédito, Aparência, Sair. Mesmo padrão
  responsivo do cabeçalho da loja.

Cada página do sistema passa a incluir o cabeçalho correspondente à sua
área (loja ou interno) logo depois dos `require_once` de autenticação,
substituindo o `<h1>`/link de "Voltar" solto que cada página tem hoje.

## Logo

`config_sistema/aparencia.php` (já existe) muda o caminho de gravação da
logo de `assets/img/loja/` para `assets/img/logo/` — mesma lógica de
upload/validação já implementada (JPEG/PNG, decodificado via GD), só o
diretório de destino muda. Fotos de produtos continuam em
`assets/img/produtos/`, sem nenhuma mudança.

A tela de aparência também ganha: seletor do `tema` (claro/escuro/
personalizado) e, só quando `personalizado` está selecionado, os 4
seletores de cor (hoje só tem 2).

## Escopo: quais páginas mudam

Nenhuma regra de negócio muda em nenhuma página — só a apresentação (CSS +
cabeçalho compartilhado). 25 páginas com interface (fora endpoints
AJAX/webhook, que não têm HTML):

**Loja Online (7):** `loja/index.php`, `loja/produto.php`,
`loja/carrinho.php`, `loja/checkout.php`, `loja/cadastro.php`,
`loja/minha_divida.php`, `loja/pedido_status.php`

**Internas (18):** `login.php`, `caixa/abertura.php`,
`caixa/index.php`, `caixa/pagamento.php`, `caixa/comprovante.php`,
`caixa/fechamento.php`, `clientes/lista.php`, `clientes/novo.php`,
`clientes/detalhe.php`, `produtos/lista.php`, `produtos/novo.php`,
`produtos/editar.php`, `produtos/categorias.php`,
`produtos/variacoes.php`, `usuarios/lista.php`, `usuarios/novo.php`,
`config_sistema/aparencia.php`, `config_sistema/entrega.php`

## Testes

Sem suíte automatizada, mesmo padrão do resto do projeto — verificação
manual rodando o sistema de verdade. Para CADA página tocada, a
verificação inclui explicitamente: carregar a página num viewport de
celular (375px de largura) e confirmar que nada quebra, nada estoura a
tela horizontalmente, e todo botão/campo continua clicável/legível — não
só a versão desktop.

## Ordem de execução

Trabalho em lotes de até 10 páginas por vez, começando pela infraestrutura
(schema, `includes/tema.php`, as 2 folhas de CSS, os 2 cabeçalhos,
`config_sistema/aparencia.php` atualizado), depois a Loja Online (7
páginas) e por fim as 18 páginas internas em dois lotes de 9. Ao final de
cada lote, um resumo é apresentado: quais páginas mudaram, o que cada uma
faz, e como testá-la manualmente — antes de seguir pro próximo lote.
