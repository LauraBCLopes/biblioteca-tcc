# Acervo — Sistema de Gerenciamento de Biblioteca (PHP + MySQL)

Projeto de TCC. Sistema completo: catálogo de livros por categoria, cadastro
de leitores, empréstimos/devoluções com prazo e renovação, bloqueio
automático por atraso, reservas e dashboard com estatísticas.

Há dois tipos de acesso, usando o **mesmo formulário de login** (por
e-mail) — o sistema identifica automaticamente qual é qual:

- **Administrador** (bibliotecário): acesso total — cadastra livros e
  leitores, registra empréstimos/devoluções, atende reservas.
- **Aluno** (leitor): cria a própria conta em `cadastro.php`. Pode
  consultar o acervo, ver os próprios empréstimos/prazos, pedir
  renovação e criar/cancelar suas próprias reservas.

Administrador **não** se autocadastra pelo `cadastro.php` público (isso seria
uma falha de segurança — qualquer visitante viraria administrador). O
primeiro é criado pelo terminal (`scripts/seed_admin.php`, passo 4 abaixo);
os demais só por um administrador já logado, na tela "Configurações", com um
limite de **2 administradores no total**. Uma conta de aluno nunca pode virar
administrador (nem o contrário) — o sistema bloqueia e-mail duplicado entre
as duas tabelas nos dois sentidos.

## Pré-requisitos

- PHP 7.2+ (o projeto evita recursos exclusivos de PHP 8) com a extensão `pdo_mysql` habilitada
- MySQL ou MariaDB
- Um ambiente local como **XAMPP**, **Laragon** ou **WAMP** (mais simples para quem vai abrir no VS Code)

## Deploy (Vercel + Supabase)

Em produção o sistema roda na **Vercel** (runtime da comunidade
[`vercel-php`](https://github.com/vercel-community/php), já configurado em
`vercel.json`) com banco de dados **Supabase** (Postgres). Localmente
continua tudo em MySQL/XAMPP normalmente — nada muda no seu ambiente.

### 1. Conectar o repositório à Vercel
No painel da Vercel: **Add New Project** → importe este repositório do
GitHub. A cada `git push` em qualquer branch, a Vercel cria automaticamente
um deploy de preview; push na branch `main` gera o deploy de produção.

### 2. Configurar as variáveis de ambiente
Em **Project Settings → Environment Variables**, cadastre as chaves
listadas em `.env.example`, usando os dados do Supabase (Project Settings →
Database → Connection string → aba **Session pooler**, porta `5432` — não
use "Direct connection", que é só IPv6 e "Transaction pooler", que não
suporta as transações/travas que o sistema usa):

```
DB_DRIVER=pgsql
DB_HOST=<host do pooler>
DB_PORT=5432
DB_NAME=postgres
DB_USER=<usuario do pooler, ex: postgres.xxxxxxxxxxxx>
DB_PASS=<senha do banco>
```

### 3. Banco de dados no Supabase
Rode o schema Postgres (`sql/schema_supabase.sql`) no SQL Editor do
Supabase antes do primeiro deploy — ele cria as tabelas, a tabela de
sessões (`sessoes`, usada pelo login em produção) e o administrador
inicial.

## Passo a passo

### 1. Colocar o projeto no servidor local
Copie a pasta `biblioteca-tcc` para dentro de `htdocs` (XAMPP) ou `www` (Laragon).

### 2. Criar o banco de dados
Abra o phpMyAdmin (ou o terminal do MySQL) e importe o arquivo `sql/schema.sql`.
Isso cria o banco `biblioteca`, todas as tabelas, as 4 categorias e alguns
livros/leitores de exemplo.

Pelo terminal, também funciona:
```bash
mysql -u root -p < sql/schema.sql
```

### 3. Configurar a conexão
Edite `config/database.php` e ajuste usuário/senha do seu MySQL se necessário
(por padrão o XAMPP/Laragon usam usuário `root` e senha vazia, que já é o
padrão deste arquivo).

### 4. Criar o administrador (login do sistema)
Pelo terminal, dentro da pasta do projeto:
```bash
php scripts/seed_admin.php
```
Ele vai pedir nome, usuário (use um e-mail) e senha — esses serão os dados de
login. Alunos não precisam desse passo: eles criam a própria conta em
`cadastro.php`.

> Se o banco `biblioteca` já existia **antes** desta atualização (login de
> alunos), rode também `sql/migracao_login_aluno.sql` — ele só adiciona a
> coluna de senha na tabela `leitores`. Quem for importar `sql/schema.sql`
> do zero não precisa desse passo.

> Se o banco já existia **antes** da atualização "Bibliotech" (rebranding +
> novos campos), rode também `sql/migracao_etapa2_bibliotech.sql` — adiciona
> os campos `editora`, `isbn`, `descricao`, `capa_url` (livros) e `matricula`
> (leitores), além das tabelas `notificacoes` e `favoritos`. No Supabase, use
> a versão `sql/migracao_etapa2_bibliotech_supabase.sql` no SQL Editor. Quem
> for importar `sql/schema.sql`/`sql/schema_supabase.sql` do zero não precisa
> desse passo.

> Se o banco já existia **antes** da solicitação de renovação, rode também
> `sql/migracao_etapa6_renovacao.sql` — adiciona a coluna
> `renovacao_solicitada` em `emprestimos`, usada pelo fluxo de aprovação
> (ver "Sobre a renovação"). No Supabase, use
> `sql/migracao_etapa6_renovacao_supabase.sql`. Quem for importar o schema do
> zero não precisa desse passo.

> Se o banco já existia **antes** do aviso de devolução pelo aluno, rode
> também `sql/migracao_devolucao_aluno.sql` — adiciona a coluna
> `devolucao_solicitada` em `emprestimos`. No Supabase, use
> `sql/migracao_devolucao_aluno_supabase.sql`. Quem for importar o schema
> do zero não precisa desse passo.

> Se o banco já existia **antes** do campo de sala/série do aluno, rode
> também `sql/migracao_turma.sql` — adiciona a coluna `turma` em
> `leitores`. No Supabase, use `sql/migracao_turma_supabase.sql`. Quem for
> importar o schema do zero não precisa desse passo.

### 5. Rodar o projeto
Se estiver usando XAMPP/Laragon, inicie o Apache e acesse:
```
http://localhost/biblioteca-tcc/login.php
```

Se preferir usar o servidor embutido do PHP (sem Apache), rode dentro da pasta:
```bash
php -S localhost:8000
```
E acesse `http://localhost:8000/login.php`.

## Estrutura do projeto

```
biblioteca-tcc/
├── assets/css/estilo.css       → visual do sistema (compartilhado por todas as páginas)
├── config/database.php        → conexão PDO com o MySQL
├── includes/auth.php          → login unificado (admin/aluno), proteção de páginas, atraso automático
├── includes/functions.php     → helpers (mensagens flash, escape de HTML)
├── includes/layout_topo.php   → cabeçalho/menu lateral, sensível ao tipo de usuário logado
├── includes/layout_rodape.php → fecha o layout das páginas internas
├── scripts/seed_admin.php     → cria o primeiro administrador
├── sql/schema.sql             → script de criação do banco de dados
├── sql/migracao_login_aluno.sql → adiciona login de aluno a um banco já existente
├── login.php / logout.php     → login único (admin ou aluno, pelo e-mail) / encerra a sessão
├── cadastro.php                → autocadastro de aluno (cria a própria conta)
├── recuperar-senha.php         → redefinição de senha
├── configuracoes.php           → dados da conta e troca de senha (admin ou aluno);
│                                  admin também cadastra outros administradores aqui (limite: 2)
├── relatorios.php              → relatórios por período + exportação CSV (?exportar=csv), admin
├── index.php                  → painel: estatísticas (admin) ou meus empréstimos/reservas (aluno)
├── livros.php                  → catálogo + cadastro/edição (?editar=ID) + exclusão (?excluir=ID), admin
├── leitores.php                → gestão de leitores: cadastro/edição, exclusão (?excluir=ID),
│                                  bloqueio/desbloqueio (?bloqueio=ID) — tudo admin
├── emprestimos.php             → circulação: registrar (POST), devolver (?devolver=ID) são admin;
│                                  renovar (?renovar=ID) é admin ou o próprio aluno
└── reservas.php                → fila de reservas: criar (POST) é admin ou o próprio aluno,
                                   atender (?atender=ID) é admin, cancelar (?cancelar=ID) é
                                   admin ou o próprio aluno
```

Cada uma dessas páginas concentra listagem + as ações do próprio recurso (uma
única página por assunto, com a ação escolhida por parâmetro na URL/POST) —
menos arquivos soltos, e também mantém o projeto bem abaixo do limite de
funções serverless do plano gratuito da Vercel.

## Sobre a Parte 3 (livros)

- Cada livro recebe um **código de chamada** único gerado automaticamente
  (ex: `RO-482.7`), com prefixo conforme a categoria.
- **Exemplares**: o formulário controla o total; a coluna "disponíveis" é
  calculada automaticamente conforme os empréstimos ativos.
- Ao **editar**, se você tentar reduzir o total abaixo da quantidade já
  emprestada, o sistema bloqueia e avisa.
- Ao **excluir**, um livro que já teve algum empréstimo (mesmo devolvido) não
  pode ser removido — isso preserva o histórico. Essa regra é reforçada tanto
  no PHP quanto na constraint `ON DELETE RESTRICT` do banco.
- Busca por título/autor e filtro por categoria funcionam juntos e mantêm o
  estado na URL (dá pra copiar/colar o link filtrado).

## Sobre leitores e login de aluno

- Cadastro com nome, e-mail (único) e telefone. O administrador pode
  cadastrar um leitor sem senha (ele ainda não tem login) — se essa pessoa
  depois se autocadastrar em `cadastro.php` com o mesmo e-mail, o sistema
  aproveita o cadastro existente e só define a senha.
- Um leitor com **empréstimo em atraso** não pode ser desbloqueado
  manualmente, nem fazer login — o sistema barra isso até a devolução ser
  registrada.
- Leitor com histórico de empréstimo (mesmo devolvido) não pode ser excluído,
  pela mesma lógica de preservar o histórico usada nos livros.

## Sobre o atraso automático

A cada página carregada (por admin ou aluno), o sistema verifica sozinho
se algum empréstimo passou do prazo: marca como `atrasado` e bloqueia o
leitor automaticamente. Não depende de tarefa agendada/cron — o próprio
uso do sistema mantém os status em dia.

## Sobre as reservas

Reservas ficam em fila por livro (ordem de chegada). Quando há exemplar
disponível, o administrador usa "Atender" para converter a reserva em
empréstimo. Alunos só veem e cancelam as próprias reservas.

## Sobre a renovação

O aluno não renova sozinho: ao clicar em "Renovar", o pedido fica
**aguardando aprovação** (coluna `renovacao_solicitada`), e o administrador
aprova (aplica +7 dias) ou recusa na tela de empréstimos. O administrador,
quando renova, já renova direto. Em qualquer caso o limite é de 2 renovações
por empréstimo, e não se renova empréstimo atrasado nem título com outro
leitor na fila de reserva.

## Sobre os relatórios

Página exclusiva do administrador (`relatorios.php`), com o período escolhido
por atalho (30 dias, 90 dias, 12 meses) ou por datas livres — o período fica
na URL, então dá pra copiar/colar o link do relatório. Mostra:

- **Resumo do período**: empréstimos, devoluções, % devolvido no prazo,
  atrasados no momento, renovações aguardando aprovação e novos leitores.
- **Movimentação mês a mês**: empréstimos e devoluções lado a lado.
- **Livros mais emprestados** e **empréstimos por categoria** no período.
- **Leitores mais ativos** no período.
- **Atrasos em aberto**: situação de hoje, independente do período.

O botão "Exportar CSV" baixa a movimentação do período (`?exportar=csv`) com
separador `;` e BOM UTF-8, pra abrir direto no Excel sem quebrar os acentos.

> Os agrupamentos por mês são feitos no PHP, e não com `DATE_FORMAT`/`TO_CHAR`,
> para a mesma consulta funcionar tanto no MySQL local quanto no Postgres do
> Supabase — mesma escolha já usada no cálculo de prazos do resto do sistema.
