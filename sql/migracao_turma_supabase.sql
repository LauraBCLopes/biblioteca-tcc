-- =========================================================
-- MIGRAÇÃO: sala/série (turma) do aluno
-- (versão Postgres/Supabase de migracao_turma.sql)
-- Rode no SQL Editor do Supabase apenas se o banco já existia antes
-- desta atualização (quem for rodar schema_supabase.sql do zero não
-- precisa disso).
-- =========================================================

ALTER TABLE leitores
  ADD COLUMN IF NOT EXISTS turma text NULL;
