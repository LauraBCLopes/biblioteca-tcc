-- =========================================================
-- MIGRAÇÃO — Etapa 2 (Bibliotech): novos campos e tabelas
-- Rode este script apenas se o banco 'biblioteca' já existia
-- ANTES desta atualização (quem for importar o schema.sql do
-- zero não precisa rodar isso — já vem tudo incluído).
--
-- Só adiciona colunas/tabelas novas; nada existente é alterado
-- ou removido.
-- =========================================================

USE biblioteca;

ALTER TABLE livros
  ADD COLUMN IF NOT EXISTS editora   VARCHAR(150) NULL AFTER ano_publicacao,
  ADD COLUMN IF NOT EXISTS isbn      VARCHAR(20)  NULL AFTER editora,
  ADD COLUMN IF NOT EXISTS descricao TEXT         NULL AFTER isbn,
  ADD COLUMN IF NOT EXISTS capa_url  VARCHAR(500) NULL AFTER descricao;

ALTER TABLE leitores
  ADD COLUMN IF NOT EXISTS matricula VARCHAR(30) NULL AFTER telefone;

CREATE TABLE IF NOT EXISTS notificacoes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_tipo   ENUM('admin', 'aluno') NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  titulo         VARCHAR(150) NOT NULL,
  mensagem       TEXT NOT NULL,
  lida           TINYINT(1) NOT NULL DEFAULT 0,
  data_criacao   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE INDEX idx_notif_usuario ON notificacoes (usuario_tipo, usuario_id, lida);

CREATE TABLE IF NOT EXISTS favoritos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  leitor_id        INT UNSIGNED NOT NULL,
  livro_id         INT UNSIGNED NOT NULL,
  data_adicionado  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fav_leitor
    FOREIGN KEY (leitor_id) REFERENCES leitores(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_fav_livro
    FOREIGN KEY (livro_id) REFERENCES livros(id)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT uq_fav_leitor_livro UNIQUE (leitor_id, livro_id)
) ENGINE=InnoDB;
