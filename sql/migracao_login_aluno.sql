-- =========================================================
-- MIGRAÇÃO: login de alunos (leitores)
-- Rode este script apenas se o banco 'biblioteca' já existia
-- ANTES desta atualização (quem for importar o schema.sql do
-- zero não precisa rodar isso — a coluna já vem incluída).
-- =========================================================

USE biblioteca;

ALTER TABLE leitores
  ADD COLUMN senha_hash VARCHAR(255) NULL AFTER telefone;
