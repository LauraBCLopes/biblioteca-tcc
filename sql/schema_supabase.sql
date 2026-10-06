-- =========================================================
-- SISTEMA DE GERENCIAMENTO DE BIBLIOTECA
-- Script de criação do banco de dados (PostgreSQL / Supabase)
--
-- Equivalente a sql/schema.sql (MySQL), adaptado para Postgres:
--   - AUTO_INCREMENT/UNSIGNED  -> bigint generated always as identity
--   - VARCHAR(n)               -> text (sem limite artificial)
--   - DATETIME                 -> timestamptz
--   - ENUM(...)                -> text + CHECK
--   - ENGINE=InnoDB            -> (não existe em Postgres, removido)
--
-- Rode este script inteiro no SQL Editor do Supabase antes do
-- primeiro deploy. Depois, rode sql/dados_atuais.sql para trazer
-- os registros que já existiam no banco local.
-- =========================================================

-- ---------------------------------------------------------
-- ADMINISTRADORES (login do sistema — bibliotecários)
-- ---------------------------------------------------------
CREATE TABLE administradores (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nome        text NOT NULL,
  usuario     text NOT NULL UNIQUE,   -- e-mail usado no login
  senha_hash  text NOT NULL,
  criado_em   timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------
-- CATEGORIAS (romance, ficção científica, educacional, obras brasileiras...)
-- ---------------------------------------------------------
CREATE TABLE categorias (
  id    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nome  text NOT NULL UNIQUE,
  slug  text NOT NULL UNIQUE
);

INSERT INTO categorias (nome, slug) VALUES
  ('Romance', 'romance'),
  ('Ficção científica', 'ficcao-cientifica'),
  ('Educacional', 'educacional'),
  ('Obras brasileiras', 'obras-brasileiras');

-- ---------------------------------------------------------
-- LEITORES (alunos — quem pega livros emprestados; também fazem login)
-- ---------------------------------------------------------
CREATE TABLE leitores (
  id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  nome           text NOT NULL,
  email          text NOT NULL UNIQUE,
  telefone       text,
  matricula      text NULL,
  turma          text NULL,     -- sala/série do aluno, ex: 8º ano B
  senha_hash     text NULL,     -- preenchido quando o aluno cria a senha em cadastro.php
  status         text NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'bloqueado')),
  data_cadastro  timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------
-- LIVROS (catálogo do acervo)
-- ---------------------------------------------------------
CREATE TABLE livros (
  id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  codigo                  text NOT NULL UNIQUE,        -- código de chamada, ex: BR-813.5
  titulo                  text NOT NULL,
  autor                   text NOT NULL,
  categoria_id            bigint NOT NULL REFERENCES categorias(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  ano_publicacao          smallint,
  editora                 text,
  isbn                    text,
  descricao               text,
  capa_url                text,        -- URL/caminho da capa; nulo usa uma capa padrão
  exemplares_total        integer NOT NULL DEFAULT 1 CHECK (exemplares_total >= 0),
  exemplares_disponiveis  integer NOT NULL DEFAULT 1 CHECK (exemplares_disponiveis >= 0),
  criado_em               timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT chk_exemplares CHECK (exemplares_disponiveis <= exemplares_total)
);

CREATE INDEX idx_livros_titulo ON livros (titulo);
CREATE INDEX idx_livros_autor  ON livros (autor);

-- ---------------------------------------------------------
-- EMPRÉSTIMOS (controle de circulação: retirada e devolução)
-- ---------------------------------------------------------
CREATE TABLE emprestimos (
  id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  livro_id          bigint NOT NULL REFERENCES livros(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  leitor_id         bigint NOT NULL REFERENCES leitores(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  data_emprestimo   date NOT NULL,
  data_prevista     date NOT NULL,           -- prazo de devolução
  data_devolucao    date NULL,               -- preenchido quando devolvido
  status                text NOT NULL DEFAULT 'ativo' CHECK (status IN ('ativo', 'atrasado', 'devolvido')),
  renovacoes            smallint NOT NULL DEFAULT 0 CHECK (renovacoes >= 0),
  renovacao_solicitada  boolean NOT NULL DEFAULT false,   -- aluno pediu renovação; aguarda aprovação do admin
  devolucao_solicitada  boolean NOT NULL DEFAULT false,   -- aluno avisou que devolveu; aguarda confirmação do admin
  criado_em             timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_emp_status ON emprestimos (status);
CREATE INDEX idx_emp_leitor ON emprestimos (leitor_id);
CREATE INDEX idx_emp_livro  ON emprestimos (livro_id);

-- ---------------------------------------------------------
-- RESERVAS (fila de espera por título indisponível)
-- ---------------------------------------------------------
CREATE TABLE reservas (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  livro_id      bigint NOT NULL REFERENCES livros(id) ON DELETE CASCADE ON UPDATE CASCADE,
  leitor_id     bigint NOT NULL REFERENCES leitores(id) ON DELETE CASCADE ON UPDATE CASCADE,
  data_reserva  date NOT NULL,
  status        text NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente', 'atendida', 'cancelada'))
);

CREATE INDEX idx_res_livro  ON reservas (livro_id);
CREATE INDEX idx_res_status ON reservas (status);

-- ---------------------------------------------------------
-- NOTIFICAÇÕES (avisos de prazo, renovação, reserva disponível...)
-- usuario_tipo + usuario_id aponta para administradores OU leitores,
-- conforme o tipo — sem FK direta, já que pode ser qualquer uma das duas.
-- ---------------------------------------------------------
CREATE TABLE notificacoes (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_tipo  text NOT NULL CHECK (usuario_tipo IN ('admin', 'aluno')),
  usuario_id    bigint NOT NULL,
  titulo        text NOT NULL,
  mensagem      text NOT NULL,
  lida          boolean NOT NULL DEFAULT false,
  data_criacao  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_notif_usuario ON notificacoes (usuario_tipo, usuario_id, lida);

-- ---------------------------------------------------------
-- FAVORITOS (livros marcados por um aluno para acompanhar depois)
-- ---------------------------------------------------------
CREATE TABLE favoritos (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  leitor_id        bigint NOT NULL REFERENCES leitores(id) ON DELETE CASCADE ON UPDATE CASCADE,
  livro_id         bigint NOT NULL REFERENCES livros(id) ON DELETE CASCADE ON UPDATE CASCADE,
  data_adicionado  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT uq_fav_leitor_livro UNIQUE (leitor_id, livro_id)
);

-- ---------------------------------------------------------
-- SESSÕES (sessão de login em produção — ver includes/sessao_pg.php)
-- Só é usada quando DB_DRIVER=pgsql (Vercel). Localmente (MySQL/XAMPP)
-- o PHP usa o handler padrão de arquivo e esta tabela não é tocada.
-- ---------------------------------------------------------
CREATE TABLE sessoes (
  id            text PRIMARY KEY,
  dados         text NOT NULL DEFAULT '',
  atualizado_em timestamptz NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------
-- Segurança: RLS ligado, sem policies para anon/authenticated.
-- Este sistema NÃO usa a API REST do Supabase nem o Supabase Auth —
-- o PHP se conecta direto ao Postgres com o usuário do pooler, que
-- tem bypassrls. Isto é só uma camada extra: garante que, se a Data
-- API do Supabase expuser essas tabelas por engano, ninguém consiga
-- ler/escrever nelas por fora do sistema.
-- ---------------------------------------------------------
ALTER TABLE administradores ENABLE ROW LEVEL SECURITY;
ALTER TABLE categorias      ENABLE ROW LEVEL SECURITY;
ALTER TABLE leitores        ENABLE ROW LEVEL SECURITY;
ALTER TABLE livros          ENABLE ROW LEVEL SECURITY;
ALTER TABLE emprestimos     ENABLE ROW LEVEL SECURITY;
ALTER TABLE reservas        ENABLE ROW LEVEL SECURITY;
ALTER TABLE notificacoes    ENABLE ROW LEVEL SECURITY;
ALTER TABLE favoritos       ENABLE ROW LEVEL SECURITY;
ALTER TABLE sessoes         ENABLE ROW LEVEL SECURITY;

-- ---------------------------------------------------------
-- Dados de exemplo (opcional — comente/apague se for rodar
-- sql/dados_atuais.sql para migrar os dados reais em seguida)
-- ---------------------------------------------------------
INSERT INTO livros (codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis) VALUES
  ('BR-813.5', 'Dom Casmurro', 'Machado de Assis', (SELECT id FROM categorias WHERE slug='obras-brasileiras'), 1899, 3, 3),
  ('FC-001.9', 'Duna', 'Frank Herbert', (SELECT id FROM categorias WHERE slug='ficcao-cientifica'), 1965, 2, 2),
  ('RO-843.2', 'Orgulho e Preconceito', 'Jane Austen', (SELECT id FROM categorias WHERE slug='romance'), 1813, 2, 2),
  ('ED-510.1', 'Introdução à Álgebra Linear', 'Elon Lages Lima', (SELECT id FROM categorias WHERE slug='educacional'), 2004, 4, 4);

-- O administrador inicial NÃO é criado aqui, pois a senha precisa ser
-- gerada com password_hash() do PHP (bcrypt). Rode scripts/seed_admin.php
-- (com DB_DRIVER=pgsql e as demais variáveis apontando para o Supabase)
-- depois de configurar o banco — veja o README. Ou, se for migrar os
-- dados reais, sql/dados_atuais.sql já traz o administrador existente.
