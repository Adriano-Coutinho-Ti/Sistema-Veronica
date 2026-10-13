# Sistema DevClub

Sistema completo para uma loja física e online: **painel administrativo**, **PDV (caixa)**, **loja online** com pagamento, **linha de crédito** para clientes e integrações opcionais (WhatsApp, frete, nota fiscal). Feito em **PHP + MySQL**, sem framework, para ser fácil de ler, instalar e adaptar.

> Material de apoio do **DevClub**. O nome do sistema, o nome da loja, a logo e as cores são configurados dentro do próprio sistema, então cada aluno entrega o seu com a identidade que quiser.

---

## O que o sistema faz

### Painel administrativo (`/dashboard.php`)
- Dashboard com vendas do dia, devedores, produtos esgotados, pedidos aguardando preparo e gráficos.
- **Produtos** com categorias, variações (tamanho, cor...), combinações com estoque e preço próprio, até 6 fotos por produto com recorte, código do produto e controle de estoque opcional.
- **Clientes** com histórico, **linha de crédito** (limite, dívidas, pagamento de dívida por Pix), lixeira de clientes e selos de e-mail/WhatsApp validado.
- **Pedidos** da loja online: acompanhar, avançar etapa, cancelar e compartilhar o link de pagamento.
- **Usuários** com perfis *Admin* e *Funcionário*.
- Configurações de **aparência** (tema claro/escuro/personalizado, logo com recorte, contato), **entrega**, **PDV** e **Mercado Pago**.

### PDV / Caixa (`/caixa`)
- Abertura e fechamento de caixa, vários caixas ao mesmo tempo.
- Venda por busca de produto ou item avulso, pagamento em dinheiro, débito, crédito, Pix (Mercado Pago), maquininha Point e linha de crédito, incluindo pagamento misto.
- Comprovante para imprimir (bobina) ou enviar por WhatsApp.

### Loja online (`/loja`)
- Catálogo com busca, categorias, favoritos e página de produto com compartilhamento.
- Carrinho com **reserva de estoque** por tempo configurável.
- Checkout com **Mercado Pago** (Pix e cartão), pagamento com **linha de crédito** e retirada na loja.
- Cadastro e login por **e-mail** e, se habilitado, por **número de WhatsApp**.
- Acompanhamento do pedido, histórico e débitos do cliente.

### Recursos opcionais
| Recurso | Quem liga | O que faz |
|---|---|---|
| **WhatsApp da loja** | Desenvolvedor habilita; o lojista conecta o número por QR code | Validação e login do cliente pelo WhatsApp (Evolution API + n8n) |
| **SuperFrete** | Desenvolvedor | Frete calculado pelo CEP na loja e impressão de etiquetas |
| **Nota fiscal** | O próprio lojista | NFC-e (cupom) no PDV e NF-e (A4) nas vendas online, pela Brasil NFe |
| **PWA** | Automático | Loja e painel instaláveis no celular e no computador |

### Painel do desenvolvedor (`/painel_dev`)
Área separada, com login próprio, para quem instala o sistema: nome do sistema, credenciais do Mercado Pago, e-mail (SMTP), caixas e maquininhas, WhatsApp, SuperFrete, travamentos (manutenção) e usuários da loja.

---

## Requisitos

- **PHP 8.1 ou superior** (desenvolvido e testado em 8.5)
- Extensões do PHP: `pdo_mysql`, `gd` (com JPEG, PNG e WebP), `curl`, `mbstring`, `openssl`, `fileinfo`
- **MySQL 8** ou **MariaDB 10.4+**
- Servidor web (Apache ou Nginx) com HTTPS — necessário para o PWA e para os webhooks de pagamento
- Uma conta de aplicativo no **Mercado Pago** para receber pagamentos online

---

## Estrutura do projeto

```
assets/            CSS, JavaScript e imagens (fotos dos produtos e logo da loja)
caixa/             PDV: abertura, venda, pagamento, comprovante, fechamento
clientes/          Cadastro, crédito, devedores e lixeira
config_sistema/    Aparência, entrega, PDV, WhatsApp, SuperFrete e nota fiscal
includes/          Regras de negócio e cabeçalhos compartilhados
integracoes/       Mercado Pago (conexão e webhook)
loja/              Loja online do cliente
notas_fiscais/     Emissão e lista de notas fiscais
painel_dev/        Painel do desenvolvedor e assistente de instalação
pedidos/           Pedidos da loja online
produtos/          Produtos, categorias e variações
SysVeronica.sql    Banco de dados completo (é o arquivo que você importa na instalação)
sql/               Scripts de atualização do banco (usados só quando o sistema é atualizado)
usuarios/          Usuários da loja
conecta_bd.php     Conexão com o banco
```

---

## Instalação

Existem **dois caminhos** para colocar o sistema no ar. Escolha um:

| Caminho | Para quem | Onde está o passo a passo |
|---|---|---|
| **VPS da Hostinger com Easypanel** (recomendado, parceira do DevClub) | Quem quer um servidor próprio, com atualização automática pelo GitHub | **Partes 1 a 13**, logo abaixo |
| **Hospedagem comum** (por exemplo, a Locaweb) | Quem prefere uma hospedagem pronta, sem administrar servidor | Seção **Instalação em hospedagem comum**, mais abaixo |

> Os dois caminhos usam o **mesmo sistema** e a mesma configuração dentro dele. O que muda é só como o sistema chega ao servidor e como o banco é criado.

Siga as partes **na ordem**, sem pular. Em cada parte, o item **"Como saber se deu certo"** diz o que você deve ver na tela antes de seguir para a próxima.

| Parte | O que você faz |
|---|---|
| 1 | Proteger o servidor com o firewall |
| 2 | Criar o projeto no Easypanel |
| 3 | Criar o banco de dados MySQL |
| 4 | Importar o banco de dados do sistema |
| 5 | Colocar o código no seu GitHub |
| 6 | Criar o aplicativo no Easypanel (buscando do GitHub) |
| 7 | Colocar o sistema na internet com o seu domínio |
| 8 | Atualização automática: o GitHub avisa o servidor |
| 9 | Proteger os dados do sistema (armazenamento) |
| 10 | Painel do desenvolvedor: primeiro acesso |
| 11 | Preparar o acesso do lojista |
| 12 | Configurar o sistema no painel do desenvolvedor (nome, rodapé, Mercado Pago, e-mail e WhatsApp) |
| 13 | Entregar o sistema ao lojista e testar |

---

### Parte 1 — Proteger o servidor com o firewall

O firewall é uma "portaria" do servidor: só deixa entrar o tráfego que você autorizou. Faça isso **antes** de colocar qualquer coisa no ar.

1. Entre no painel da **Hostinger** (hPanel).
2. No menu, clique em **VPS**.
3. Clique em **Gerenciar** na sua VPS.
4. No menu lateral, clique em **Segurança** e depois em **Firewall**.
5. Clique em **Adicionar firewall** e dê um nome a ele (por exemplo, `devclub`).
6. Crie as regras de **entrada** abaixo. Todas com ação **Aceitar** e origem **Qualquer**:

| Porta | Protocolo | Para que serve |
|---|---|---|
| `22` | TCP | Acesso ao servidor por SSH |
| `80` | TCP | Sites por HTTP e emissão do certificado de segurança |
| `443` | TCP | Sites por HTTPS (o cadeado no navegador) |
| `3000` | TCP | Painel do Easypanel pelo endereço IP (só até configurar o domínio) |

7. **Ative** o firewall para a sua VPS.

> **Importante — não libere a porta `3306` (MySQL).** O sistema fala com o banco por dentro do servidor, então ela nunca precisa ficar aberta para a internet. Banco de dados exposto é uma das invasões mais comuns.

> **Dica:** depois que o painel do Easypanel abrir pelo seu domínio com HTTPS, você pode **remover a regra da porta `3000`**, já que ela é um acesso sem criptografia.

**Como saber se deu certo:** o painel da Hostinger mostra o firewall como **Ativo** para a sua VPS, e o Easypanel continua abrindo normalmente. Se o endereço com domínio parar de abrir depois de ativar o firewall, quase sempre falta a regra da porta `80` ou `443`.

---

### Parte 2 — Criar o projeto no Easypanel

O **Easypanel** é o painel instalado na sua VPS. Nele você cria os serviços (banco de dados, o sistema, e os módulos extras) sem digitar comandos. Um **projeto** é uma pastinha que agrupa tudo que pertence ao mesmo sistema.

1. Abra o Easypanel (o endereço do seu domínio ou `http://IP-DA-VPS:3000`) e entre com o seu usuário.
2. No menu lateral, clique em **Painel**.
3. Clique em **Criar Projeto**.
4. Em **Nome**, digite `sys-devclub` e confirme.
5. O projeto abre vazio, com o título **sys-devclub** e a área **Serviços**. É aqui que vamos criar os serviços das próximas partes.

> **Módulos extras (opcionais):** o sistema também funciona com **n8n** e **Evolution API**, usados no recurso de WhatsApp. Se você for usar esse recurso, crie os dois serviços neste mesmo projeto. A configuração deles está nas aulas do DevClub sobre n8n e Evolution API, então **não é repetida aqui**. Sem eles, o sistema funciona normalmente, apenas sem o WhatsApp.

---

### Parte 3 — Criar o banco de dados MySQL

O banco de dados é onde ficam todos os dados da loja: produtos, clientes, vendas e pedidos. Vamos criá-lo dentro do projeto e depois criar um **usuário seu** para acessá-lo.

#### 3.1 Criar o serviço MySQL

1. Com o projeto `sys-devclub` aberto, na área **Serviços**, clique no **+** (ou em **Serviço**) e escolha **MySQL**.
2. Preencha:

| Campo | O que digitar |
|---|---|
| **Nome do Serviço** | `sysveronica` |
| **Nome do Banco de Dados** | `SysVeronica` |

> **Atenção às letras maiúsculas e minúsculas.** No servidor Linux, `SysVeronica` e `sysveronica` são nomes **diferentes**. Digite exatamente como está na tabela: o serviço em minúsculas, o banco com `S` e `V` maiúsculos.

3. Clique em **Criar**.
4. Aguarde o serviço ficar com a bolinha **verde**, que significa que está rodando.

O Easypanel cria o banco e já gera um usuário e uma senha automáticos para ele. **Não vamos usar esses dados no sistema.** Vamos criar o seu próprio usuário no passo seguinte.

#### 3.2 Abrir o phpMyAdmin

O **phpMyAdmin** é uma tela web para administrar o banco. Ele já vem como opção do serviço MySQL.

1. Abra o serviço `sysveronica` clicando nele.
2. Procure a opção **phpMyAdmin**.
3. Clique em **Ativar** e aguarde alguns instantes.
4. Clique em **Abrir**. O phpMyAdmin abre em uma nova aba, já mostrando o banco **SysVeronica** na lista da esquerda.

O banco está criado.

#### 3.3 Criar o seu usuário de acesso

Vamos criar um usuário com o seu nome para você administrar o banco.

1. No phpMyAdmin, clique na aba **Contas de usuário**.
2. Clique em **Adicionar conta de usuário**.
3. Preencha:

| Campo | O que digitar |
|---|---|
| **Nome de usuário** | `DevMaster` |
| **Nome do host** | deixe **Qualquer host** (o símbolo `%`) |
| **Senha** | uma senha forte, de sua escolha |
| **Digite novamente** | a mesma senha |

> **Senha de exemplo usada nesta aula:** `DevMaster@2026-Dev!`
>
> **Não use essa senha no seu servidor de verdade.** Ela está escrita aqui, em um texto público, então qualquer pessoa conhece. Crie a sua própria senha forte: com **12 ou mais caracteres**, misturando letras maiúsculas e minúsculas, números e símbolos. **Anote a sua senha num lugar seguro**, porque ela será pedida na hora de conectar o sistema ao banco.

4. **Deixe desmarcada** a opção que cria um banco com o mesmo nome do usuário.
5. Na seção **Privilégios globais**, marque **todas** as opções das três colunas:
   - **Dados**
   - **Estrutura**
   - **Administração**

   (Dica: cada coluna tem um link **Marcar todos**.)
6. Role até o final da página e clique em **Executar**.

**Como saber se deu certo:** na lista de **Contas de usuário** aparece a linha `DevMaster` com os privilégios `ALL PRIVILEGES`. O usuário foi criado e está pronto.

#### 3.4 Anote os dados do banco

Guarde estas informações. Elas serão usadas na Parte 9 e na Parte 10:

| Informação | Valor neste exemplo |
|---|---|
| Nome do serviço | `sysveronica` |
| Nome do banco | `SysVeronica` |
| Usuário | `DevMaster` |
| Senha | a que você criou |
| Endereço (host) do banco | `sysveronica` (o nome do serviço). Se o sistema não conseguir conectar, tente `sys-devclub_sysveronica`, que é `nome-do-projeto_nome-do-serviço` _(será usado na Parte 9 e na Parte 10)_ |

---

### Parte 4 — Importar o banco de dados do sistema

Na Parte 3 você criou o banco **vazio**. Agora vamos colocar dentro dele todas as tabelas do sistema (produtos, clientes, vendas, pedidos...). Isso é uma **importação**: você escolhe um arquivo `.sql` que já vem pronto no projeto e o phpMyAdmin faz o resto.

> **Onde está o arquivo do banco?** Ele se chama **`SysVeronica.sql`** e vem junto com o projeto, na **pasta raiz** (a primeira pasta do projeto, junto do `index.php`, e **não** dentro da pasta `sql/`). Você vai precisar dele no seu computador, então se ainda não baixou o projeto, faça a **Parte 5, passo 1**, e volte aqui.

1. Abra o **phpMyAdmin** do serviço `sysveronica` (Easypanel → projeto `sys-devclub` → serviço `sysveronica` → **Abrir** no phpMyAdmin).
2. Na lista da **esquerda**, clique no banco **`SysVeronica`**. Ele precisa ficar **selecionado** (destacado). É importante: o arquivo será importado dentro do banco que estiver selecionado.
3. No menu do alto, clique na aba **Importar**.
4. Em **Arquivo a importar**, clique em **Escolher arquivo** e selecione o arquivo **`SysVeronica.sql`**, que está na pasta raiz do projeto.
5. **Não mexa** nas outras opções. Deixe tudo como está.
6. Role até o final da página e clique no botão **Importar**.

**Como saber se deu certo:** aparece uma faixa **verde** com a mensagem de importação concluída com sucesso. Na lista da esquerda, ao clicar no sinal de **+** ao lado de `SysVeronica`, aparecem as tabelas do sistema (`produtos`, `clientes`, `vendas`, `usuarios` e outras). O banco de dados está pronto e **online**.

> **Importe uma vez só.** Se você importar de novo no mesmo banco, o phpMyAdmin mostra um erro dizendo que as tabelas já existem. Isso não apaga nada; apenas não repita.

---

### Parte 5 — Colocar o código no seu GitHub

O servidor vai buscar o sistema no **GitHub**, mas buscando do **seu** repositório, não do nosso. Assim você controla a sua cópia, pode alterar o que quiser e, quando enviar uma mudança, o servidor se atualiza. **Este passo é obrigatório.**

> Você já aprendeu a usar Git e GitHub nas aulas do DevClub (iniciar o Git no VS Code, fazer commit e push). Aqui está só o roteiro aplicado a este projeto.

**Passo 1 — Baixar o projeto**
1. Acesse o repositório do sistema: <https://github.com/Adriano-Coutinho-Ti/Sistema-Veronica>
2. Clique no botão verde **Code**.
3. Clique em **Download ZIP**.
4. Quando o arquivo terminar de baixar, **extraia** (descompacte) o ZIP em uma pasta do seu computador.

**Passo 2 — Criar o seu repositório no GitHub**
1. No GitHub, na sua conta, crie um **repositório novo** (botão **New**).
2. Dê um nome a ele, por exemplo `sistema-loja`.
3. Deixe-o **vazio**: não marque a opção de criar README, `.gitignore` ou licença.
4. Prefira **Público**. Se escolher **Privado**, o servidor precisa de uma chave de acesso do GitHub configurada no Easypanel, e esse passo extra não é coberto aqui.

**Passo 3 — Enviar o projeto para o seu repositório**
1. Abra a pasta do projeto no **VS Code**.
2. Inicie o Git na pasta (**Inicializar Repositório**, na aba de controle de código-fonte).
3. Faça o **commit** de todos os arquivos, com uma mensagem como `primeira versão`.
4. Conecte o projeto ao repositório que você criou no GitHub e faça o **push** para a branch **`main`**.

**Como saber se deu certo:** ao atualizar a página do seu repositório no GitHub, você vê as pastas do sistema (`caixa`, `loja`, `produtos`, `sql` e as demais) e a branch **`main`** selecionada.

---

### Parte 6 — Criar o aplicativo no Easypanel

Agora você vai dizer ao Easypanel onde está o código, para ele montar o servidor do sistema sozinho.

1. No Easypanel, abra o projeto **`sys-devclub`**.
2. Clique em **+ Serviço**.
3. Escolha **Aplicativo**.
4. Em **Nome**, digite `sistema-veronica` e confirme. O aplicativo é criado.
5. Abra a aba **Fonte**. Ela pergunta de onde vem o código. Há a opção de enviar um arquivo ZIP, mas para trabalhar de forma profissional vamos **buscar do GitHub**. Marque a opção **Github**.
6. Preencha:

| Campo | O que digitar |
|---|---|
| **Repositório** | O **seu** repositório, no formato `SEU-USUARIO/NOME-DO-SEU-REPOSITORIO` (veja abaixo como copiar) |
| **Ramo (branch)** | `main` |

   **Como preencher o repositório (atenção!).** Aqui você informa o repositório **que você criou na Parte 5**, não o do instrutor. O Easypanel já escreve `github.com /` na frente do campo, então você digita **somente o que vem depois de `https://github.com/`**.

   Pegue o endereço do seu repositório no navegador e **apague a parte `https://github.com/`**, ficando só `usuário/repositório`:

| Endereço no navegador | O que você digita no campo |
|---|---|
| `https://github.com/` `Adriano-Coutinho-Ti/Sistema-Veronica` | `Adriano-Coutinho-Ti/Sistema-Veronica` |
| `https://github.com/` `maria-silva/minha-loja` | `maria-silva/minha-loja` |

   Os exemplos acima usam o repositório do instrutor (`Adriano-Coutinho-Ti/Sistema-Veronica`) **apenas para você ver como se copia**. **Não digite esse**: use o seu usuário e o nome do repositório que você criou.

   **Não copie** `https://github.com/` (nem o `https://` sozinho, nem uma barra no final). Se você colar o endereço inteiro, o Easypanel não encontra o repositório.

   Se você seguiu a Parte 5 direitinho, a sua branch é a `main`.

7. Clique em **Salvar**.
8. Na seção **Construção**, escolha **Nixpacks**.

> **Por que Nixpacks?** O sistema é feito em PHP. O Nixpacks olha os arquivos do projeto, descobre sozinho que é PHP e prepara o servidor (PHP, servidor web e extensões) para rodá-lo. Você não precisa configurar nada disso.

9. Clique em **Salvar** de novo.
10. Logo depois de salvar, aparece uma mensagem parecida com esta:

   ```
   Waiting for service sys-devclub_sistema-veronica to start...
   ```

   **Assim que essa mensagem aparecer, clique em Implantar.** (Repare no nome: é `nome-do-projeto_nome-do-serviço`. Você vai usar esse mesmo padrão mais adiante para achar o endereço do banco.)
11. Aguarde. O Easypanel baixa o código do GitHub, monta o servidor e liga o aplicativo. **Isso demora alguns minutos** na primeira vez. Você acompanha pela aba **Implantações**: quando a linha ficar com a marca verde ✔, terminou.

**Como saber se deu certo:** na aba **Implantações** a implantação aparece com ✔ verde e o aplicativo mostra a bolinha **verde** (rodando).

Neste ponto o **sistema já está implantado no servidor**. Mas ainda faltam algumas configurações no próprio servidor (conectar ao banco, domínio, guardar as imagens...) **antes** de entrar no painel do desenvolvedor. É o que fazemos a partir da Parte 7.

---

### Parte 7 — Colocar o sistema na internet com o seu domínio

Até aqui o sistema abre apenas em um endereço de teste que o Easypanel criou sozinho. Agora vamos fazer o **seu** subdomínio apontar para o sistema, para ele ter um nome de verdade na internet (por exemplo, `loja-dev.seudominio.com.br`).

São dois passos: primeiro você diz ao **provedor do seu domínio** para onde o nome deve apontar, e depois você diz ao **Easypanel** que esse nome pertence ao sistema.

#### 7.1 Apontar o subdomínio para a VPS (no provedor do domínio)

1. Entre no site onde o seu domínio é gerenciado (o lugar onde você registrou ou administra o domínio: Registro.br, Locaweb, Hostinger, GoDaddy...).
2. Procure a área de **Entradas de DNS** (também chamada de *Zona de DNS* ou *Gerenciar DNS*).
3. Crie um **novo registro** do tipo **A** com estes dados:

| Campo | O que colocar |
|---|---|
| **Tipo** | `A` |
| **Nome / Host / Alias** | o subdomínio que você escolheu, só a primeira parte. Exemplo: `loja-dev` |
| **Conteúdo / Valor / Aponta para** | **apenas o endereço IP da sua VPS**. Exemplo: `187.127.45.15` |

4. Salve.

> **Atenção: aqui vai somente o IP.** Sem `http://`, sem `https://`, sem `:80`, sem barra no final. O IP da sua VPS aparece no painel da Hostinger, na página da VPS.
>
> **Erro comum:** colar o endereço que aparece no Easypanel, como `http://sys-devclub_sistema-veronica:80/`. Esse endereço só existe **dentro** do servidor. O provedor do domínio responde **"O IP informado não é válido"** e não deixa salvar.

> Cada provedor tem telas diferentes, com nomes um pouco diferentes. O que importa é o que foi pedido: um registro **A**, com o seu subdomínio, apontando para o IP da VPS.

#### 7.2 Cadastrar o domínio no Easypanel

1. Volte ao Easypanel e abra o projeto **`sys-devclub`**.
2. Abra o aplicativo **`sistema-veronica`**.
3. No menu, clique em **Domínios**.
4. Clique em **Adicionar Domínio**.
5. No campo **Host**, digite o subdomínio completo que você criou. Exemplo: `loja-dev.seudominio.com.br`.
6. Deixe a opção **HTTPS** **ativada**. É ela que garante o cadeado de segurança no navegador.
7. Clique em **Salvar**.

> O domínio de teste que o Easypanel criou sozinho (aquele que começa com `sys-devclub-...`) pode ficar onde está. Não precisa apagar.

#### 7.3 Esperar e conferir

O DNS leva um tempo para "espalhar" pela internet: de alguns minutos até cerca de 1 hora. Para conferir, abra o **PowerShell** (Windows) e rode, trocando pelo seu subdomínio:

```powershell
nslookup loja-dev.seudominio.com.br
```

Quando a resposta mostrar o IP da sua VPS, o DNS já está valendo.

**Como saber se deu certo:** abra `https://loja-dev.seudominio.com.br` no navegador. A página do sistema carrega, com o **cadeado** ao lado do endereço. O certificado de segurança é emitido sozinho pelo Easypanel; se aparecer "conexão não segura" logo no começo, espere alguns minutos e atualize. Isso só funciona porque as portas `80` e `443` estão liberadas no firewall (Parte 1).

---

### Parte 8 — Atualização automática: o GitHub avisa o servidor

Hoje, para atualizar o sistema no servidor, você precisaria entrar no Easypanel e clicar em **Implantar** toda vez. Vamos automatizar: **sempre que você enviar uma mudança para o GitHub, o GitHub avisa o servidor, e o servidor busca a versão nova sozinho.**

**Passo 1 — Copiar o gatilho de implantação**
1. No Easypanel, abra o aplicativo `sistema-veronica` e clique em **Implantações**.
2. Role até **Gatilho de Implantação**. Aparece um endereço parecido com este:

```
http://187.127.45.15:3000/api/deploy/0123456789abcdef0123456789abcdef
```

3. Copie esse endereço.

**Passo 2 — Trocar o começo do endereço (muito importante)**

O Easypanel mostra o endereço com o **IP** e a porta `3000`. Nós **não** vamos usar isso, porque agora temos o domínio com HTTPS. Troque só o começo, e **mantenha o final** (`/api/deploy/` e o código):

| Como o Easypanel mostra | Como você deve usar |
|---|---|
| `http://187.127.45.15:3000/api/deploy/0123456789abcdef0123456789abcdef` | `https://loja-dev.seudominio.com.br/api/deploy/0123456789abcdef0123456789abcdef` |

> O código no final do endereço (aqui um exemplo inventado) é **secreto**: quem o conhecer consegue mandar o seu servidor implantar. Não publique, não coloque em print e não envie para ninguém. Se vazar, clique em **Atualizar Token de Implantação** na mesma tela do Easypanel e refaça o Passo 3.

**Passo 3 — Cadastrar no GitHub**
1. No GitHub, abra o **seu** repositório (aquele onde você colocou o sistema na Parte 5).
2. Clique em **Settings**.
3. No menu lateral, clique em **Webhooks**.
4. Clique em **Add webhook**.
5. Preencha:

| Campo | O que colocar |
|---|---|
| **Payload URL** | o endereço que você acabou de montar no Passo 2 (com o **código dentro**) |
| **Content type** | `application/json` |
| **Secret** | deixe **vazio** (o código já vai dentro do endereço) |
| **SSL verification** | **Enable SSL verification** marcado |
| **Which events...** | **Just the push event** marcado |
| **Active** | marcado |

6. Clique em **Add webhook**.

**Como saber se deu certo:** na lista de webhooks, abra o que você criou e veja a aba **Recent Deliveries**: uma entrega com a marca verde ✔ significa que o servidor recebeu o aviso. Daqui para a frente, **cada push que você fizer no GitHub gera uma implantação nova no Easypanel** (aba **Implantações**), que leva alguns minutos.

---

### Parte 9 — Proteger os dados do sistema (armazenamento)

Existe uma regra do servidor que você precisa conhecer: **a cada atualização, ou quando a VPS reinicia o serviço, o Easypanel apaga tudo o que está dentro do aplicativo e coloca no lugar os arquivos novos do GitHub.**

Isso é ótimo para o código, mas **péssimo para o que o sistema salva sozinho**: as fotos dos produtos, a logo da loja e o arquivo com a senha do banco. Se você não avisar o servidor, **tudo isso some a cada atualização**.

A solução é dizer ao servidor: "esta pasta e este arquivo **não podem ser perdidos**". No Easypanel isso se chama **Armazenamento**.

1. No Easypanel, abra o aplicativo `sistema-veronica`.
2. No menu, clique em **Armazenamento**.
3. Você verá 3 botões. Vamos usar só dois: **Adicionar Montagem de Volume** e **Adicionar Montagem de Arquivo**.

#### 9.1 Proteger as pastas de imagens (volumes)

Clique em **Adicionar Montagem de Volume** e crie estes dois, um de cada vez:

| Nome | Caminho de Montagem |
|---|---|
| `imagens_produtos` | `/app/assets/img/produtos` |
| `imagens_logo` | `/app/assets/img` |

O primeiro protege as **fotos dos produtos**. O segundo protege a pasta de imagens inteira, que inclui a **logo da loja**.

#### 9.2 Proteger o arquivo de conexão com o banco

Clique em **Adicionar Montagem de Arquivo** e preencha:

**Caminho de Montagem:**
```
/app/config_credenciais.php
```

**Conteúdo:**
```php
<?php
// ATENÇÃO: isto é só um exemplo, troque pelos SEUS dados (os da Parte 3).
$host = 'sysveronica';
$dbname = 'SysVeronica';
$username = 'DevMaster';
$password = 'DevMaster@2026-Dev!';
```

> **Troque pelos seus dados reais.** Use o nome do serviço, o nome do banco, o usuário e a senha que **você** criou e anotou na Parte 3.
>
> **Por que isso importa:** o Easypanel recria este arquivo com o texto que está no campo **Conteúdo** a cada atualização. Se o Conteúdo estiver vazio ou com dados de exemplo, depois da próxima atualização o sistema perde a conexão com o banco. Por isso o Conteúdo deve ter **os seus dados verdadeiros**.

Depois de criar os **3 pontos de armazenamento**, aguarde o aplicativo ficar de novo com a bolinha **verde**.

#### 9.3 Liberar a permissão de escrita

O sistema precisa **gravar** fotos, logo e o arquivo de conexão. Para isso, a pasta precisa permitir escrita. Vamos liberar pelo terminal do próprio aplicativo:

1. Ainda dentro do aplicativo `sistema-veronica`, clique no ícone do terminal **`>_`** (na barra de botões, no alto).
2. Inicie o terminal escolhendo **Bash**.
3. Ele já abre na pasta raiz do sistema. Digite o comando abaixo, **com o ponto no final**, e pressione Enter:

```bash
chmod -R 777 .
```

> O `.` (ponto) no final significa "esta pasta". Sem ele o comando dá erro.
>
> Este comando dá permissão total de leitura e escrita a tudo dentro do aplicativo. Para o aprendizado e para este servidor, que roda um único sistema, é o jeito mais simples. Se depois de uma atualização o sistema reclamar que "não consegue gravar", rode o comando de novo.

#### 9.4 Liberar a pasta de imagens

As fotos dos produtos e a logo ficam na pasta de imagens, que é um volume (Parte 9.1). Esse volume é criado vazio pelo servidor, e o sistema precisa de permissão para **gravar** dentro dele. Vamos liberar essa pasta:

1. No Easypanel, abra o projeto **`sys-devclub`** e o aplicativo **`sistema-veronica`**.
2. Clique no ícone do terminal **`>_`** e inicie com **Bash**.
3. Rode o comando abaixo e pressione Enter:

```bash
chmod -R a+rwX /app/assets/img
```

Pronto: a partir daqui o sistema já consegue **subir a logo da loja e as fotos dos produtos**. Se um dia o envio de foto ou de logo avisar que "não tem permissão para gravar", rode esse comando de novo.

---

### Parte 10 — Painel do desenvolvedor: primeiro acesso

O **painel do desenvolvedor** é a área de quem instala o sistema: ali você configura o nome do sistema, o Mercado Pago, o e-mail, o WhatsApp e outras opções. Ele fica em um endereço separado, no seu próprio domínio.

#### 10.1 Abrir o painel

No navegador, acrescente `/painel_dev/login.php` ao final do **seu** domínio:

```
https://loja-dev.seudominio.com.br/painel_dev/login.php
```

> O exemplo usa o domínio do instrutor só para você ver o formato. Use o seu.

#### 10.2 Senha de instalação

Se tudo deu certo até aqui, abre uma tela chamada **Senha de instalação**. Ela aparece quando você acessa **pela primeira vez** e ainda não existe nenhuma conexão com o banco configurada.

- Digite a senha de instalação padrão: **`DevMaster`**
- Clique em **Continuar**.

#### 10.3 Conectar o sistema ao banco

A próxima tela pede os dados do banco que você criou na Parte 3. Preencha com os **seus** dados (os da tabela "Anote os dados do banco"). No exemplo da aula:

| Campo | Exemplo |
|---|---|
| **Host** | `sysveronica` |
| **Nome do banco** | `SysVeronica` |
| **Usuário** | `DevMaster` |
| **Senha** | `DevMaster@2026-Dev!` _(a senha de exemplo; use a sua)_ |

Clique para conectar. O sistema avisa que **o banco está conectado**, e você já pode acessar o painel.

#### 10.4 Entrar no painel

Para o primeiro acesso, use o usuário e a senha temporária:

| Campo | O que digitar |
|---|---|
| **Usuário** | `DevMaster` |
| **Senha** | `2026@DevSys` |

> Esta senha é **temporária**, vale só para o primeiro acesso. Você vai trocá-la agora.

Ao entrar pela primeira vez, o sistema abre sozinho a tela **Completar cadastro**. Preencha o seu **nome**, o seu **e-mail** e crie a **sua senha**, e salve.

Se quiser mudar esses dados mais tarde, no painel do desenvolvedor clique no card **Minha conta**: lá você altera nome, e-mail e senha e salva.

**Parabéns! O sistema já está online e acessível.** Falta configurar a loja em si (primeiro usuário Admin, Mercado Pago e demais recursos), nas próximas partes.

---

### Parte 11 — Preparar o acesso do lojista

O **lojista** é o seu cliente: a pessoa que vai usar o sistema no dia a dia (cadastrar produtos, vender, atender pedidos). Ele entra por um painel diferente do seu. O banco já vem com **um usuário cadastrado** para o lojista, e você só precisa definir a senha dele.

1. Entre no **painel do desenvolvedor** (Parte 10).
2. No menu do alto, clique em **Usuários da loja**.
3. Na lista aparece o usuário já cadastrado, com o e-mail **`sistema@gmail.com`**.
4. Nessa linha, clique em **Resetar senha**.
5. Digite a senha que você quer usar para o primeiro acesso (mínimo de 6 caracteres) e clique em **Salvar nova senha**.

Anote o e-mail e essa senha: você vai usá-los na Parte 13 para entrar no painel do lojista.

---

### Parte 12 — Configurar o sistema no painel do desenvolvedor

Ainda no **painel do desenvolvedor**, a tela principal (**Configurações do sistema**) é formada por **quadrados** (cards). Cada quadrado abre uma janela de configuração. Vamos configurar os principais, um de cada vez.

#### 12.1 Nome do sistema
Abra o quadrado **Nome do sistema** (o terceiro).

Digite o nome do sistema que você vai oferecer. Exemplo: `SYS Gravetos`. Esse nome aparece **apenas para o lojista**, no cabeçalho do painel dele e na tela de login. Salve.

#### 12.2 Crédito no rodapé da loja
Abra o quadrado **Crédito no rodapé da loja** (o quarto). É o "Desenvolvido por..." que aparece no rodapé da loja online.

| Campo | O que colocar | Exemplo |
|---|---|---|
| **Nome** | o seu nome ou o da sua empresa | `Sistema DevClub` |
| **Link** | o seu site | `https://www.devclub.com.br/` |

Salve.

#### 12.3 Mercado Pago (aplicativo)

O **Mercado Pago** é quem processa os pagamentos da loja (Pix e cartão). Para o seu sistema usá-lo, você cria um **aplicativo** no Mercado Pago Developers e copia alguns códigos para o painel. É o passo mais longo, então vá com calma.

Abra o quadrado **Mercado Pago (aplicativo)**. Ele mostra, na janela, três informações que você vai usar no Mercado Pago:
- o link do **Mercado Pago Developers**;
- a **Redirect URI** (cadastrar no aplicativo, aba OAuth);
- a **URL de webhook** (cadastrar no aplicativo, aba Webhooks).

Deixe essa janela aberta (ou abra em outra aba) e vá ao Mercado Pago.

**Passo 1 — Criar o aplicativo**
1. Acesse o **Mercado Pago Developers** (o link está na janela do painel) e entre com a sua conta do Mercado Pago.
2. Clique em **Integrações**. Abre a tela **Gestão de integrações**.
3. Clique em **Criar aplicação**.
4. Escolha **Criar no painel de integração**. (**Não** use a opção com IA.)
5. Em **Escolha uma solução para integrar**, selecione **Checkout Pro**.
6. Em **Tipo de API**, escolha **API de Preferences**.
7. Em **Nome da aplicação**, digite o nome do seu sistema. Exemplo: `SYS-Gravetos`.
8. Clique em **Criar aplicação**.

**Passo 2 — Cadastrar o endereço de retorno (Redirect URI)**
1. Com o aplicativo criado, abra **Configurações da aplicação** e depois **Configuração avançada**.
2. Procure o campo **"Adicione a URL de redirecionamento se sua integração for feita com OAuth"**.
3. Cole ali a **Redirect URI** que está na janela do painel do desenvolvedor. Ela é o seu domínio mais o caminho do sistema. Exemplo:

```
https://loja-dev.seudominio.com.br/integracoes/mercado_pago/callback.php
```

4. Clique em **Adicionar nova URL** e depois em **Salvar alterações**.

**Passo 3 — Cadastrar o webhook (aviso de pagamentos)**
1. No aplicativo, abra **Webhooks** e clique em **Configurar notificações**.
2. Escolha **Modo de produção**.
3. No campo **URL de produção**, cole a **URL de webhook** que está na janela do painel do desenvolvedor. Exemplo:

```
https://loja-dev.seudominio.com.br/integracoes/mercado_pago/webhook.php
```

4. Marque os eventos **Pagamentos**, **Contestações** e **Order (Mercado Pago)**.
5. Clique em **Salvar configurações**.
6. O Mercado Pago passa a mostrar a **Assinatura secreta**. **Copie** esse código: ele vai no campo **Webhook Secret** do painel.

**Passo 4 — Ativar as credenciais de produção**
1. No aplicativo, abra **Credenciais de produção**.
2. Escolha um **Setor** (a área de atuação do seu negócio).
3. Informe o **site**: o endereço do seu sistema. Exemplo: `https://loja-dev.seudominio.com.br/`.
4. Clique em **Ativar credenciais de produção**.
5. Aparecem o **Client ID** e o **Client Secret**. **Copie os dois.**

**Passo 5 — Colocar tudo no painel do desenvolvedor**
Volte à janela **Mercado Pago (aplicativo)** e preencha:

| Campo | O que colocar |
|---|---|
| **Client ID** | o Client ID copiado no Passo 4 |
| **Client Secret** | o Client Secret copiado no Passo 4 |
| **Webhook Secret** | a Assinatura secreta copiada no Passo 3 |
| **Taxa de marketplace (%)** | o percentual que você, dono do aplicativo, cobra sobre cada venda paga pelo Mercado Pago. Precisa ser **maior que 0** |

Clique em **Salvar Mercado Pago**. O aplicativo está configurado.

> Depois disso, o **lojista** ainda precisa conectar a conta do Mercado Pago **dele** ao sistema, no painel dele (**Configurações → Mercado Pago**). Quem recebe o dinheiro das vendas é a conta do lojista; o aplicativo que você criou é a "ponte" que permite essa conexão.

#### 12.4 E-mail (SMTP)

O sistema envia e-mails para os clientes da loja (por exemplo, o código para confirmar o cadastro). Para isso ele precisa dos dados de **envio de e-mail (SMTP)** da sua conta de e-mail. Abra o quadrado **E-mail (SMTP)** e preencha com os dados que o seu provedor de e-mail fornece:

| Campo | O que colocar |
|---|---|
| **Host** | o servidor de envio de e-mail do seu provedor |
| **Porta** | a porta de envio informada pelo provedor |
| **Usuário** | o usuário (normalmente o próprio endereço de e-mail) |
| **Senha** | a senha dessa conta de e-mail |
| **E-mail de envio** | o endereço que aparece como remetente |
| **Nome de exibição** | o nome que o cliente vê como remetente |

Salve.

#### 12.5 WhatsApp (Evolution API + n8n)

Este recurso é **opcional**. Ele permite que o cliente da loja valide o cadastro e entre usando o **número de WhatsApp**, além do e-mail. Ele depende de dois serviços que você já aprendeu a instalar nas aulas do DevClub: a **Evolution API** e o **n8n**. Se você não for usar, pule esta parte: o sistema funciona normalmente só com e-mail.

Abra o quadrado **WhatsApp (Evolution API + n8n)**.

**URL base da Evolution API**
É o endereço que você usa para abrir a sua Evolution API, **sem o `/manager/` no final**. Exemplo: se você abre `https://api.seudominio.com.br/manager/`, coloque:

```
https://api.seudominio.com.br
```

**API Key global**
1. No **Easypanel**, abra o serviço da sua **Evolution API**.
2. No menu, clique em **Ambiente**.
3. Procure, lá embaixo, a variável **`AUTHENTICATION_API_KEY`**.
4. O valor que vem **depois do sinal de igual** é a sua API Key global. Copie e cole no campo.

**URL do webhook do n8n**
O sistema avisa o n8n quando precisa mandar uma mensagem, e o n8n manda pelo WhatsApp. Para isso você cria um **workflow** no n8n:

1. Na janela do WhatsApp no painel do desenvolvedor, logo abaixo do botão **Salvar WhatsApp**, clique em **Baixar o modelo do workflow do n8n**. Um arquivo é baixado.
2. Abra o seu **n8n**.
3. Clique em **Create Workflow** (criar workflow).
4. Dê um nome ao workflow.
5. Clique nos **três pontinhos** (`⋯`) e escolha **Import from file...**.
6. Selecione o arquivo que você baixou. O workflow aparece montado.
7. **Ative** o workflow (botão **Active**, no alto).
8. Clique no primeiro bloco, o **Webhook**, e copie a **Production URL** (a URL de **produção**, não a de teste). Ela é parecida com:

```
https://n8n.seudominio.com.br/webhook/aviso
```

9. Volte ao painel do desenvolvedor, cole essa URL no campo **URL do webhook do n8n**, marque **Ativar login/validação por WhatsApp** e clique em **Salvar WhatsApp**.

> Para a janela do painel, o botão **Onde eu acho essas informações?** também explica cada campo.
>
> Com isso o recurso fica **habilitado**, mas ele só passa a funcionar na loja quando o **lojista conecta o número de WhatsApp dele**, lendo um QR code no painel dele (**Configurações → WhatsApp**). Enquanto não houver um número conectado, o cliente usa só o e-mail.

---

### Parte 13 — Entregar o sistema ao lojista e testar

Agora vamos entrar no sistema **como o lojista**, completar os dados dele e conferir se está tudo funcionando.

1. Abra o endereço do seu sistema com `/login.php` no final. Exemplo:

```
https://loja-dev.seudominio.com.br/login.php
```

2. Entre com o e-mail **`sistema@gmail.com`** e a senha que você definiu na Parte 11.
3. No menu, clique em **Configurações** e depois em **Usuários**.
4. Na linha do usuário, clique em **Editar** e coloque o **nome** e o **e-mail do seu cliente**. Salve.
5. Dali em diante o seu cliente entra com os dados dele.

**Antes de entregar, teste.** Navegue pelas páginas do painel e confira se tudo abre e se os dados esperados aparecem:
- **Produtos**: a lista abre, com os produtos que já vieram cadastrados.
- **Clientes**: a lista abre, com os clientes já cadastrados.
- Abra também o **Dashboard**, **Pedidos** e a loja online (o seu domínio, sem `/login.php`), e experimente enviar uma **foto** em um produto e a **logo** em **Configurações → Aparência**.

Se tudo estiver certo, é só **entregar o sistema para o seu cliente**.

> **Pronto!** Esta é a instalação em uma **VPS**. A instalação em uma **hospedagem comum** (por exemplo, a Locaweb) é diferente e está na próxima seção.

---

## Instalação em hospedagem comum (ex.: Locaweb)

Aqui o sistema vai para uma **hospedagem pronta**, em vez de uma VPS. Você não administra servidor: a hospedagem já vem com PHP, banco de dados MySQL e um painel para enviar arquivos. O exemplo usa a **Locaweb**; em outras hospedagens os nomes dos menus mudam um pouco, mas os passos são os mesmos.

**O que muda em relação à VPS**

| | VPS (Easypanel) | Hospedagem comum |
|---|---|---|
| Como o sistema chega ao servidor | Easypanel busca no **GitHub** | Você **envia os arquivos** pelo gerenciador de arquivos |
| Banco de dados | Serviço MySQL criado no Easypanel | Banco criado no **painel da hospedagem** |
| Firewall, Easypanel, GitHub, webhook | Sim (Partes 1, 2, 5, 6, 8) | **Não precisa** |
| Armazenamento e `chmod` (Parte 9) | Sim, senão os arquivos somem a cada atualização | **Não precisa**: os arquivos enviados ficam no lugar |
| Painel do desenvolvedor, Mercado Pago, e-mail, WhatsApp, entrega ao lojista | Partes 10 a 13 | **Igual**, com os dados da hospedagem |

> Antes de começar, confira que a hospedagem usa **PHP 8.1 ou superior** (veja a seção *Requisitos*). O painel da hospedagem permite escolher a versão do PHP.

### H1 — Ter um domínio

O domínio é o endereço do seu sistema (por exemplo, `minhaloja.com.br`). Os domínios `.com.br` são registrados no **Registro.br**.

1. Acesse o **Registro.br** e entre (ou crie) a sua conta.
2. Pesquise o nome que você quer. Se estiver disponível, registre.
3. Guarde o endereço do domínio que você registrou: vamos cadastrá-lo na hospedagem.

> Se você já tem um domínio, pule este passo.

### H2 — Criar a hospedagem

1. Entre no **Painel de Hospedagens** da Locaweb.
2. Clique em **Nova hospedagem**.
3. Cadastre o **domínio** que você registrou no passo anterior (por exemplo, `minhaloja.com.br`).
4. Conclua o cadastro. A Locaweb **instala o seu servidor**, e isso leva um tempo. Aguarde até a hospedagem aparecer como **pronta**.

> O domínio registrado no Registro.br precisa apontar para a hospedagem. A própria Locaweb mostra, ao cadastrar o domínio, o que fazer no Registro.br para ele funcionar. Siga a orientação da tela.

### H3 — Definir a senha e abrir o gerenciador de arquivos

1. Na lista de hospedagens, clique em **Administrar** no domínio que você está usando. O painel de administração abre.
2. Clique em **Alterar senha** e defina a **sua senha** de acesso aos arquivos. **Anote** essa senha.
3. Ainda no painel, abra o **Gerenciador de arquivos web**.
4. Ele pede os **dados de acesso**: informe o usuário e a senha que você acabou de definir. Confirme.

### H4 — Enviar os arquivos do sistema

1. No gerenciador de arquivos, abra a pasta **`/public_html`**. Ela é a pasta pública do site: tudo o que estiver ali aparece na internet.
2. Envie para dentro dela **todos os arquivos do sistema**, que são os arquivos do projeto que você baixou na **Parte 5, Passo 1** (e extraiu no seu computador).

> **Atenção ao enviar:** envie **o conteúdo** da pasta do projeto, e não a pasta inteira por fora. O arquivo `index.php` e as pastas `caixa`, `loja`, `produtos`, `sql` e as demais precisam ficar **direto dentro de `/public_html`**.
>
> Exemplo certo: `/public_html/index.php` e `/public_html/loja/index.php`.
> Exemplo errado: `/public_html/Sistema-Veronica/index.php`. Nesse caso o sistema só abriria em `seudominio.com.br/Sistema-Veronica/`.

**Como saber se deu certo:** ao abrir o gerenciador de arquivos em `/public_html`, você vê as pastas do sistema e o `index.php` logo ali.

### H5 — Criar o banco de dados

1. No painel da hospedagem, clique em **Banco de dados**.
2. Abra o **Gerenciar banco de dados**.
3. Clique em **Novo banco** e preencha os dados do banco novo, do mesmo jeito que você fez na VPS (Parte 3): um **nome para o banco**, um **usuário** e uma **senha forte**.
4. **Anote os dados do banco.** Além do nome, do usuário e da senha, anote o **endereço do servidor de banco (host)** que a hospedagem mostra. Ele é diferente do da VPS: costuma ser um endereço comprido, parecido com `nomedobanco.mysql.dbaas.com.br`.

**Importar as tabelas do sistema**

5. Abra o **phpMyAdmin** da hospedagem (há um botão ou link para ele no gerenciador de banco de dados).
6. Selecione o banco que você criou, clique na aba **Importar**, escolha o arquivo **`SysVeronica.sql`** (da pasta raiz do projeto) e clique em **Importar**. É o mesmo processo da **Parte 4**.

**Como saber se deu certo:** uma faixa verde confirma a importação, e as tabelas do sistema aparecem na lista do banco.

### H6 — Configurar o sistema

A partir daqui o processo é **o mesmo da VPS**, usando o seu domínio:

1. **Parte 10 — Painel do desenvolvedor: primeiro acesso.** Abra `https://seudominio.com.br/painel_dev/login.php`, informe a senha de instalação e, na hora de conectar ao banco, use os dados do banco **da hospedagem** (aquele **host** comprido que você anotou no passo H5). Nesse caminho o sistema grava o arquivo de conexão direto na pasta, e ele **permanece** lá.
2. **Parte 11** — preparar o acesso do lojista.
3. **Parte 12** — configurar nome do sistema, rodapé, Mercado Pago, e-mail e WhatsApp.
4. **Parte 13** — entregar o sistema ao lojista e testar.

> **Mercado Pago, e-mail, WhatsApp e n8n são iguais nos dois caminhos.** Eles não dependem de onde o sistema está instalado: a configuração da Parte 12 (aplicativo no Mercado Pago, SMTP, Evolution API e workflow do n8n) é **exatamente a mesma** na VPS e na hospedagem comum. Só muda o endereço do seu sistema nos campos do Mercado Pago (Redirect URI e URL de webhook), que deve ser o do seu domínio. A Evolution API e o n8n continuam onde você os instalou; o sistema só conversa com eles pela internet.

> **Use HTTPS.** O Mercado Pago só envia os avisos de pagamento para endereços `https://`, e o aplicativo instalável (PWA) também exige. Ative o **certificado SSL** do seu domínio no painel da hospedagem e use sempre o endereço com `https://`.

**Se o envio de foto ou de logo avisar que "não tem permissão para gravar":** pelo gerenciador de arquivos, abra a pasta `assets/img` e ajuste as permissões para permitir escrita.

**Pronto: o sistema está online na hospedagem.** O restante do uso é igual ao da VPS.

---

## Configuração depois de instalar

Com o sistema no ar e entregue ao lojista, estes são os próximos ajustes. O **lojista** faz a maior parte no painel dele (menu **Configurações**); o **desenvolvedor** cuida dos recursos opcionais no painel do desenvolvedor.

**Pelo lojista (painel da loja, menu Configurações)**

| Onde | Para que serve |
|---|---|
| **Aparência** | Nome da loja, **logo** (com recorte), tema (claro, escuro ou personalizado) e contato (WhatsApp, e-mail, endereço e horário), que aparecem na loja online |
| **Entrega** | Formas de entrega e retirada na loja, com prazo e custo |
| **PDV** | Tempo de reserva do carrinho e tempo para pagar na loja online |
| **Mercado Pago** | Conectar a **conta do lojista** ao sistema; é por ela que o lojista recebe os pagamentos |
| **Usuários** | Cadastrar funcionários (perfil Funcionário) e administradores (perfil Admin) |

Antes de vender, o lojista também cadastra as **categorias** e os **produtos** (menu **Produtos**), com fotos, variações e estoque.

**Recursos opcionais**

| Recurso | Quem liga | O que fazer |
|---|---|---|
| **WhatsApp da loja** | O desenvolvedor habilita (Parte 12.5); o lojista conecta | No painel do lojista, **Configurações → WhatsApp**, ler o **QR code** com o celular da loja. Para trocar de número, desconectar e conectar de novo |
| **SuperFrete** (frete por CEP e etiquetas) | O desenvolvedor, no quadrado **SuperFrete** do painel do desenvolvedor | Escolher o modo e, depois, o lojista cadastra CEP de origem e serviços em **Configurações → SuperFrete**. Cada produto passa a pedir peso e tamanho da caixa |
| **Nota fiscal** | O próprio lojista, em **Configurações → Nota fiscal** | Conectar a conta da **Brasil NFe**, aceitar a declaração de responsabilidade e ligar. Aparece uma seção **Dados fiscais** em cada produto |
| **App instalável (PWA)** | Automático | O navegador oferece **Instalar aplicativo** (Chrome e Edge no computador e no Android). No iPhone: Compartilhar → Adicionar à Tela de Início |

---

## Arquivos que precisam ser preservados

Estes arquivos e pastas são **criados pelo uso do sistema**, não vêm no GitHub, e **não podem ser perdidos** em uma atualização:

| O que | Para que serve |
|---|---|
| `config_credenciais.php` | Dados de conexão com o banco |
| `assets/img/produtos/` | Fotos dos produtos |
| `assets/img/logo/` | Logo da loja |

- **Na VPS:** é para isso que existem os pontos de **Armazenamento** (Parte 9). Eles guardam essas pastas e o arquivo fora da área que o servidor apaga a cada atualização. O **Conteúdo** do arquivo de conexão deve ter os seus dados reais.
- **Na hospedagem comum:** ao reenviar arquivos para atualizar o sistema, **não envie por cima** do `config_credenciais.php` nem das pastas `assets/img/produtos` e `assets/img/logo`.

---

## Atualizando o sistema

**VPS (com o webhook da Parte 8):**
1. Faça a mudança no seu computador e envie ao GitHub (commit e push na branch `main`).
2. O GitHub avisa o Easypanel, que implanta a versão nova sozinho. Acompanhe pela aba **Implantações**; leva alguns minutos.
3. Sem o webhook, entre no Easypanel e clique em **Implantar** manualmente.

**Hospedagem comum:**
1. Pelo **Gerenciador de arquivos web**, envie para `/public_html` os arquivos que mudaram, **substituindo** os antigos.
2. Não substitua os arquivos listados em *Arquivos que precisam ser preservados*.

**Em qualquer caminho, o banco de dados:** quando uma atualização trouxer um **script novo na pasta `sql/`**, ele precisa ser executado **uma única vez** no phpMyAdmin (aba **Importar**, ou aba **SQL**, colando o conteúdo). O código novo só funciona depois que o banco recebe a alteração. Execute **antes** de publicar o código que depende dela.

---

## Problemas comuns

| O que aconteceu | Causa provável | O que fazer |
|---|---|---|
| Depois de ativar o firewall da Hostinger, o domínio deixa de abrir (mas o IP com `:3000` abre) | Faltam as regras das portas **80** e **443** | Parte 1: crie as regras de entrada para `80` e `443` |
| O provedor do domínio diz **"O IP informado não é válido"** | Foi colado um endereço (como `http://sys-devclub_sistema-veronica:80/`) em vez do IP | Parte 7.1: no registro **A**, coloque **só o IP** da VPS, sem `http://` nem `:80` |
| O navegador mostra **"conexão não segura"** logo depois de configurar o domínio | O certificado ainda não foi emitido ou o DNS ainda não valeu | Espere alguns minutos e confira com `nslookup` (Parte 7.3); confirme as portas 80 e 443 |
| O webhook do GitHub não dispara a implantação | A URL está sem o `/api/deploy/` e o código, ou usa o IP em vez do domínio | Parte 8: use `https://seudominio/api/deploy/SEU-CODIGO`, deixe o **Secret** vazio e veja **Recent Deliveries** no GitHub |
| A **logo** ou as **fotos** somem depois de uma atualização | Faltam os pontos de armazenamento | Parte 9.1: crie os dois volumes de imagens |
| Aparece **"não tem permissão para gravar"** ao enviar foto ou logo | A pasta de imagens não tem permissão de escrita | Parte 9.4: `chmod -R a+rwX /app/assets/img` no terminal do aplicativo |
| Depois de uma atualização, o sistema perde a conexão com o banco ou abre a tela de **Senha de instalação** de novo | O **Conteúdo** do arquivo de conexão no Armazenamento está vazio ou com dados de exemplo | Parte 9.2: coloque no Conteúdo os **seus dados reais** do banco |
| Ao importar o banco aparece erro de **tabela já existente** | O arquivo foi importado duas vezes no mesmo banco | Não repita. Use um banco vazio para importar do zero |
| O painel do desenvolvedor não conecta ao banco | Host, nome do banco, usuário ou senha errados (cuidado com maiúsculas: `SysVeronica` é diferente de `sysveronica`) | Confira os dados anotados na Parte 3. Na VPS, se `sysveronica` não funcionar, tente `sys-devclub_sysveronica` |
| Os pagamentos da loja não funcionam | O Mercado Pago do lojista não está conectado, ou faltam o webhook ou o Webhook Secret | Parte 12.3, e o lojista deve conectar a conta em **Configurações → Mercado Pago**. O **Dashboard** avisa quando há algo assim |
| O cliente da loja não recebe o código por WhatsApp | O recurso está habilitado, mas o lojista não conectou o número | **Configurações → WhatsApp**, ler o QR code. Confira também o workflow ativo no n8n |
| Um produto aparece com um desenho de "Produto indisponível" | O produto não tem foto | É o comportamento normal: envie uma foto em **Produtos → Editar** |
| Um produto não consegue sair em nota fiscal | Faltam dados fiscais (NCM e grupo tributário) | No cadastro do produto, preencha a seção **Dados fiscais** |

---

## Créditos
Sistema DevClub, material para os alunos do **DevClub**.
