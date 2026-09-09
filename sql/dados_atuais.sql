-- Dados reais migrados do MySQL local (gerado automaticamente)

-- categorias
INSERT INTO categorias (id, nome, slug) VALUES (1, 'Romance', 'romance') ON CONFLICT (id) DO NOTHING;
INSERT INTO categorias (id, nome, slug) VALUES (2, 'Ficção científica', 'ficcao-cientifica') ON CONFLICT (id) DO NOTHING;
INSERT INTO categorias (id, nome, slug) VALUES (3, 'Educacional', 'educacional') ON CONFLICT (id) DO NOTHING;
INSERT INTO categorias (id, nome, slug) VALUES (4, 'Obras brasileiras', 'obras-brasileiras') ON CONFLICT (id) DO NOTHING;

-- livros
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis) VALUES (1, 'BR-813.5', 'Dom Casmurro', 'Machado de Assis', 4, 1899, 3, 3) ON CONFLICT (id) DO NOTHING;
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis) VALUES (2, 'FC-001.9', 'Duna', 'Frank Herbert', 2, 1965, 2, 2) ON CONFLICT (id) DO NOTHING;
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis) VALUES (3, 'RO-843.2', 'Orgulho e Preconceito', 'Jane Austen', 1, 1813, 2, 2) ON CONFLICT (id) DO NOTHING;
INSERT INTO livros (id, codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis) VALUES (4, 'ED-510.1', 'Introdução à Álgebra Linear', 'Elon Lages Lima', 3, 2004, 4, 4) ON CONFLICT (id) DO NOTHING;

-- leitores (alunos) -- senha_hash NULL = ainda nao fez login/autocadastro
INSERT INTO leitores (id, nome, email, telefone, senha_hash, status) VALUES (1, 'Ana Beatriz Alves', 'ana.souza@email.com', '(17) 99811-2233', NULL, 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, senha_hash, status) VALUES (3, 'Heloize Cristina do Nascimento', 'heloizecnasc@gmail.com', '(17) 9999-9999', NULL, 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, senha_hash, status) VALUES (4, 'Tayla da Costa Pacheco', 'taylinha1800@gmail.com', '(17) 98456-3697', NULL, 'ativo') ON CONFLICT (id) DO NOTHING;
INSERT INTO leitores (id, nome, email, telefone, senha_hash, status) VALUES (6, 'Heloize Cristina Do Nascimento', 'heloize@aluno.etec.com.br', '170988652133', '$2y$10$ds5KvxSvYV6Hb9ldpWnwnOKsWqmqHle/meeHznV603T7MuXwjLazG', 'ativo') ON CONFLICT (id) DO NOTHING;

-- administradores (hash bcrypt preservado -- login continua igual)
INSERT INTO administradores (id, nome, usuario, senha_hash) VALUES (1, 'Laura Beatriz Colombelli Lopes', 'laurabclopes@admin.com.br', '$2y$10$pXIJCzPubRxlmYpclYzuLO.3mfhDY7ZXwAN.k9FfuTu2pi93dpafK') ON CONFLICT (id) DO NOTHING;

-- ajusta as sequences dos identity columns para continuar depois do maior id migrado
SELECT setval(pg_get_serial_sequence('categorias', 'id'), COALESCE((SELECT MAX(id) FROM categorias), 1));
SELECT setval(pg_get_serial_sequence('livros', 'id'), COALESCE((SELECT MAX(id) FROM livros), 1));
SELECT setval(pg_get_serial_sequence('leitores', 'id'), COALESCE((SELECT MAX(id) FROM leitores), 1));
SELECT setval(pg_get_serial_sequence('administradores', 'id'), COALESCE((SELECT MAX(id) FROM administradores), 1));
