-- =========================================================
-- MIGRAÇÃO: aluno avisa a devolução, admin confirma
-- (versão Postgres/Supabase de migracao_devolucao_aluno.sql)
-- Rode no SQL Editor do Supabase apenas se o banco já existia antes
-- desta atualização (quem for rodar schema_supabase.sql do zero não
-- precisa disso).
-- =========================================================

ALTER TABLE emprestimos
  ADD COLUMN IF NOT EXISTS devolucao_solicitada boolean NOT NULL DEFAULT false;
