-- =========================================================
-- MIGRAÇÃO — Etapa 6 (Bibliotech): solicitação de renovação
-- (versão Postgres/Supabase de migracao_etapa6_renovacao.sql)
-- Rode no SQL Editor do Supabase apenas se o banco já existia antes
-- desta atualização (quem for rodar schema_supabase.sql do zero não
-- precisa disso).
-- =========================================================

ALTER TABLE emprestimos
  ADD COLUMN IF NOT EXISTS renovacao_solicitada boolean NOT NULL DEFAULT false;
