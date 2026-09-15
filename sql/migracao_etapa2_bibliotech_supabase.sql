-- =========================================================
-- MIGRAÇÃO — Etapa 2 (Bibliotech): novos campos e tabelas
-- (versão Postgres/Supabase de migracao_etapa2_bibliotech.sql)
--
-- Rode no SQL Editor do Supabase apenas se o banco já tiver sido
-- criado ANTES desta atualização (quem for rodar o
-- schema_supabase.sql do zero não precisa disso — já vem incluído).
--
-- Só adiciona colunas/tabelas novas; nada existente é alterado
-- ou removido.
-- =========================================================

ALTER TABLE livros
  ADD COLUMN IF NOT EXISTS editora   text,
  ADD COLUMN IF NOT EXISTS isbn      text,
  ADD COLUMN IF NOT EXISTS descricao text,
  ADD COLUMN IF NOT EXISTS capa_url  text;

ALTER TABLE leitores
  ADD COLUMN IF NOT EXISTS matricula text;

CREATE TABLE IF NOT EXISTS notificacoes (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  usuario_tipo  text NOT NULL CHECK (usuario_tipo IN ('admin', 'aluno')),
  usuario_id    bigint NOT NULL,
  titulo        text NOT NULL,
  mensagem      text NOT NULL,
  lida          boolean NOT NULL DEFAULT false,
  data_criacao  timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_notif_usuario ON notificacoes (usuario_tipo, usuario_id, lida);
ALTER TABLE notificacoes ENABLE ROW LEVEL SECURITY;

CREATE TABLE IF NOT EXISTS favoritos (
  id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  leitor_id        bigint NOT NULL REFERENCES leitores(id) ON DELETE CASCADE ON UPDATE CASCADE,
  livro_id         bigint NOT NULL REFERENCES livros(id) ON DELETE CASCADE ON UPDATE CASCADE,
  data_adicionado  timestamptz NOT NULL DEFAULT now(),
  CONSTRAINT uq_fav_leitor_livro UNIQUE (leitor_id, livro_id)
);
ALTER TABLE favoritos ENABLE ROW LEVEL SECURITY;
