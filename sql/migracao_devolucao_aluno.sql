-- =========================================================
-- MIGRAÇÃO: aluno avisa a devolução, admin confirma
-- Rode apenas se o banco já existia ANTES desta atualização
-- (quem for importar sql/schema.sql do zero não precisa disso).
--
-- Adiciona a coluna 'devolucao_solicitada' em 'emprestimos': quando o
-- aluno avisa que devolveu o livro, ela fica marcada aqui até um
-- administrador confirmar (registra a devolução) ou recusar (só limpa
-- a marca, ex.: o livro não chegou na biblioteca).
-- =========================================================

USE biblioteca;

ALTER TABLE emprestimos
  ADD COLUMN IF NOT EXISTS devolucao_solicitada TINYINT(1) NOT NULL DEFAULT 0 AFTER renovacao_solicitada;
