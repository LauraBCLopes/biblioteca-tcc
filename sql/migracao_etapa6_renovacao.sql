-- =========================================================
-- MIGRAÇÃO — Etapa 6 (Bibliotech): solicitação de renovação
-- Rode apenas se o banco já existia ANTES desta atualização
-- (quem for importar sql/schema.sql do zero não precisa disso).
--
-- Adiciona a coluna 'renovacao_solicitada' em 'emprestimos': quando um
-- aluno pede renovação, ela fica marcada aqui até um administrador
-- aprovar (aplica os +7 dias) ou recusar (só limpa a marca).
-- =========================================================

USE biblioteca;

ALTER TABLE emprestimos
  ADD COLUMN IF NOT EXISTS renovacao_solicitada TINYINT(1) NOT NULL DEFAULT 0 AFTER renovacoes;
