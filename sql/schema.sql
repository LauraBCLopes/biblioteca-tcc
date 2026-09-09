-- =========================================================
-- SISTEMA DE GERENCIAMENTO DE BIBLIOTECA
-- Script de criação do banco de dados (MySQL / MariaDB)
-- =========================================================

CREATE DATABASE IF NOT EXISTS biblioteca
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE biblioteca;

-- ---------------------------------------------------------
-- ADMINISTRADORES (login do sistema — bibliotecários)
-- ---------------------------------------------------------
CREATE TABLE administradores (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  usuario       VARCHAR(60)  NOT NULL UNIQUE,
  senha_hash    VARCHAR(255) NOT NULL,
  criado_em     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- CATEGORIAS (romance, ficção científica, educacional, obras brasileiras...)
-- ---------------------------------------------------------
CREATE TABLE categorias (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(60) NOT NULL UNIQUE,
  slug   VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

INSERT INTO categorias (nome, slug) VALUES
  ('Romance', 'romance'),
  ('Ficção científica', 'ficcao-cientifica'),
  ('Educacional', 'educacional'),
  ('Obras brasileiras', 'obras-brasileiras');

-- ---------------------------------------------------------
-- LEITORES (usuários da biblioteca — quem pega livros emprestados)
-- ---------------------------------------------------------
CREATE TABLE leitores (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(150) NOT NULL,
  email           VARCHAR(150) NOT NULL UNIQUE,
  telefone        VARCHAR(30),
  senha_hash      VARCHAR(255) NULL,        -- preenchido quando o aluno cria sua senha em cadastro.php
  status          ENUM('ativo', 'bloqueado') NOT NULL DEFAULT 'ativo',
  data_cadastro   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- LIVROS (catálogo do acervo)
-- ---------------------------------------------------------
CREATE TABLE livros (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo                 VARCHAR(20) NOT NULL UNIQUE,        -- código de chamada, ex: BR-813.5
  titulo                 VARCHAR(200) NOT NULL,
  autor                  VARCHAR(150) NOT NULL,
  categoria_id           INT UNSIGNED NOT NULL,
  ano_publicacao         SMALLINT UNSIGNED,
  exemplares_total       INT UNSIGNED NOT NULL DEFAULT 1,
  exemplares_disponiveis INT UNSIGNED NOT NULL DEFAULT 1,
  criado_em              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_livro_categoria
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT chk_exemplares CHECK (exemplares_disponiveis <= exemplares_total)
) ENGINE=InnoDB;

CREATE INDEX idx_livros_titulo ON livros (titulo);
CREATE INDEX idx_livros_autor  ON livros (autor);

-- ---------------------------------------------------------
-- EMPRÉSTIMOS (controle de circulação: retirada e devolução)
-- ---------------------------------------------------------
CREATE TABLE emprestimos (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  livro_id          INT UNSIGNED NOT NULL,
  leitor_id         INT UNSIGNED NOT NULL,
  data_emprestimo   DATE NOT NULL,
  data_prevista     DATE NOT NULL,           -- prazo de devolução
  data_devolucao    DATE NULL,               -- preenchido quando devolvido
  status            ENUM('ativo', 'atrasado', 'devolvido') NOT NULL DEFAULT 'ativo',
  renovacoes        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  criado_em         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_emp_livro
    FOREIGN KEY (livro_id) REFERENCES livros(id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_emp_leitor
    FOREIGN KEY (leitor_id) REFERENCES leitores(id)
    ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_emp_status ON emprestimos (status);
CREATE INDEX idx_emp_leitor ON emprestimos (leitor_id);
CREATE INDEX idx_emp_livro  ON emprestimos (livro_id);

-- ---------------------------------------------------------
-- RESERVAS (fila de espera por título indisponível)
-- ---------------------------------------------------------
CREATE TABLE reservas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  livro_id       INT UNSIGNED NOT NULL,
  leitor_id      INT UNSIGNED NOT NULL,
  data_reserva   DATE NOT NULL,
  status         ENUM('pendente', 'atendida', 'cancelada') NOT NULL DEFAULT 'pendente',
  CONSTRAINT fk_res_livro
    FOREIGN KEY (livro_id) REFERENCES livros(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_res_leitor
    FOREIGN KEY (leitor_id) REFERENCES leitores(id)
    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Dados de exemplo (opcional — ajuda a testar o sistema)
-- ---------------------------------------------------------
INSERT INTO livros (codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis) VALUES
  ('BR-813.5', 'Dom Casmurro', 'Machado de Assis', (SELECT id FROM categorias WHERE slug='obras-brasileiras'), 1899, 3, 3),
  ('FC-001.9', 'Duna', 'Frank Herbert', (SELECT id FROM categorias WHERE slug='ficcao-cientifica'), 1965, 2, 2),
  ('RO-843.2', 'Orgulho e Preconceito', 'Jane Austen', (SELECT id FROM categorias WHERE slug='romance'), 1813, 2, 2),
  ('ED-510.1', 'Introdução à Álgebra Linear', 'Elon Lages Lima', (SELECT id FROM categorias WHERE slug='educacional'), 2004, 4, 4);

INSERT INTO leitores (nome, email, telefone) VALUES
  ('Ana Beatriz Souza', 'ana.souza@email.com', '(17) 99811-2233'),
  ('Carlos Eduardo Lima', 'carlos.lima@email.com', '(17) 99722-4455');

-- O administrador inicial NÃO é criado aqui, pois a senha precisa ser
-- gerada com password_hash() do PHP (bcrypt). Rode o script
-- scripts/seed_admin.php depois de configurar o banco — veja o README.
