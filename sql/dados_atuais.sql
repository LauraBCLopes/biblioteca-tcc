-- Dados reais migrados do MySQL local (gerado automaticamente em 2026-09-16)
--
-- Precisa de "OVERRIDING SYSTEM VALUE" em cada INSERT que define o "id" à
-- mão: as colunas de sql/schema_supabase.sql são "GENERATED ALWAYS AS
-- IDENTITY", e sem essa cláusula o Postgres recusa o valor explícito
-- (erro: "cannot insert a non-DEFAULT value into column \"id\"").

-- categorias
INSERT INTO categorias (id, nome, slug) OVERRIDING SYSTEM VALUE VALUES (1, 'Romance', 'romance') ON CONFLICT (id) DO NOTHING;
INSERT INTO categorias (id, nome, slug) OVERRIDING SYSTEM VALUE VALUES (2, 'Ficção científica', 'ficcao-cientifica') ON CONFLICT (id) DO NOTHING;
INSERT INTO categorias (id, nome, slug) OVERRIDING SYSTEM VALUE VALUES (3, 'Educacional', 'educacional') ON CONFLICT (id) DO NOTHING;
INSERT INTO categorias (id, nome, slug) OVERRIDING SYSTEM VALUE VALUES (4, 'Obras brasileiras', 'obras-brasileiras') ON CONFLICT (id) DO NOTHING;

-- livros
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, editora, isbn, descricao, capa_url, exemplares_total, exemplares_disponiveis) OVERRIDING SYSTEM VALUE VALUES (1, 'BR-813.5', 'Dom Casmurro', 'Machado de Assis', 4, 1899, NULL, NULL, NULL, NULL, 3, 3) ON CONFLICT (id) DO NOTHING;
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, editora, isbn, descricao, capa_url, exemplares_total, exemplares_disponiveis) OVERRIDING SYSTEM VALUE VALUES (2, 'FC-001.9', 'Duna', 'Frank Herbert', 2, 1965, NULL, NULL, NULL, NULL, 2, 2) ON CONFLICT (id) DO NOTHING;
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, editora, isbn, descricao, capa_url, exemplares_total, exemplares_disponiveis) OVERRIDING SYSTEM VALUE VALUES (3, 'RO-843.2', 'Orgulho e Preconceito', 'Jane Austen', 1, 1813, NULL, NULL, NULL, NULL, 2, 2) ON CONFLICT (id) DO NOTHING;
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, editora, isbn, descricao, capa_url, exemplares_total, exemplares_disponiveis) OVERRIDING SYSTEM VALUE VALUES (4, 'ED-510.1', 'Introdução à Álgebra Linear', 'Elon Lages Lima', 3, 2004, NULL, NULL, NULL, NULL, 4, 4) ON CONFLICT (id) DO NOTHING;

-- leitores (alunos) -- senha_hash NULL = ainda nao fez login/autocadastro
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (1, 'Ana Beatriz Alves', 'ana.souza@email.com', '(17) 99811-2233', NULL, NULL, 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (3, 'Heloize Cristina do Nascimento', 'heloizecnasc@gmail.com', '(17) 9999-9999', NULL, NULL, 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (4, 'Tayla da Costa Pacheco', 'taylinha1800@gmail.com', '(17) 98456-3697', NULL, NULL, 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (6, 'Heloize Cristina Do Nascimento', 'heloize@aluno.etec.com.br', '170988652133', NULL, '$2y$10$ds5KvxSvYV6Hb9ldpWnwnOKsWqmqHle/meeHznV603T7MuXwjLazG', 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (7, 'Laura Beatriz Colombelli Lopes', 'laurablopes@aluno.etec.com.br', '17988556422', NULL, '$2y$10$NMU5epDwx94ozY.eC0hYBu.IoiHOzGMXuFTzELVcwN6q51efJfxhy', 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (8, 'Heloize Cristina do Nascimento', 'helocdonasc@admin.com.br', '17988556422', NULL, '$2y$10$avUp2PyGXLm6MNWdrmTpz.GOajyrJ4KECAAcaLh8AkhXJEMCIhfo2', 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, matricula, senha_hash, status) OVERRIDING SYSTEM VALUE VALUES (9, 'Heloize Cristina do Nascimento', 'heloizecnasc@aluno.com.br', '17988564421', NULL, '$2y$10$TYLo0avt3nBSa17fbHbjZuXFh8hxzcSdyW2FaRQa8jYWMUpmFPIp6', 'ativo') ON CONFLICT (id) DO NOTHING;

-- administradores (hash bcrypt preservado -- login continua igual)
INSERT INTO administradores (id, nome, usuario, senha_hash) OVERRIDING SYSTEM VALUE VALUES (1, 'Laura Beatriz Colombelli Lopes', 'laurabclopes@admin.com.br', '$2y$10$pXIJCzPubRxlmYpclYzuLO.3mfhDY7ZXwAN.k9FfuTu2pi93dpafK') ON CONFLICT (id) DO NOTHING;

-- emprestimos
-- nenhum empréstimo localmente

-- reservas
INSERT INTO reservas (id, livro_id, leitor_id, data_reserva, status) OVERRIDING SYSTEM VALUE VALUES (1, 1, 9, '2026-09-15', 'cancelada') ON CONFLICT (id) DO NOTHING;

-- ajusta as sequences dos identity columns para continuar depois do maior id migrado
SELECT setval(pg_get_serial_sequence('categorias', 'id'), COALESCE((SELECT MAX(id) FROM categorias), 1));
SELECT setval(pg_get_serial_sequence('livros', 'id'), COALESCE((SELECT MAX(id) FROM livros), 1));
SELECT setval(pg_get_serial_sequence('leitores', 'id'), COALESCE((SELECT MAX(id) FROM leitores), 1));
SELECT setval(pg_get_serial_sequence('administradores', 'id'), COALESCE((SELECT MAX(id) FROM administradores), 1));
SELECT setval(pg_get_serial_sequence('emprestimos', 'id'), COALESCE((SELECT MAX(id) FROM emprestimos), 1));
SELECT setval(pg_get_serial_sequence('reservas', 'id'), COALESCE((SELECT MAX(id) FROM reservas), 1));
