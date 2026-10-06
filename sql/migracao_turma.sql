-- =========================================================
-- MIGRAÇÃO: sala/série (turma) do aluno
-- Rode apenas se o banco já existia ANTES desta atualização
-- (quem for importar sql/schema.sql do zero não precisa disso).
-- =========================================================

USE biblioteca;

ALTER TABLE leitores
  ADD COLUMN IF NOT EXISTS turma VARCHAR(30) NULL AFTER matricula;
