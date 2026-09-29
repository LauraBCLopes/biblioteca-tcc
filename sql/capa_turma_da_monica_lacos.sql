-- =========================================================
-- Capa de "Turma da Mônica: Laços" (HQ-004)
--
-- A imagem fica em assets/img/capas/turma-da-monica-lacos.png
-- (publicada junto com o site). Funciona no MySQL e no Supabase.
-- =========================================================

UPDATE livros
   SET capa_url = 'assets/img/capas/turma-da-monica-lacos.png'
 WHERE codigo = 'HQ-004'
    OR lower(titulo) = lower('Turma da Mônica: Laços');
