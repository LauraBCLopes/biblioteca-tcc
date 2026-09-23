-- =========================================================
-- ACERVO AMPLIADO (Postgres / Supabase)
--
-- Adiciona 10 novos gêneros e ~60 livros conhecidos, espalhados por
-- todas as categorias. Rode no SQL Editor do Supabase.
--
-- Pode rodar mais de uma vez sem duplicar nada:
--   - categoria que já existe (mesmo nome ou slug) é ignorada;
--   - livro com título ou código já cadastrado é ignorado.
--
-- ISBN, editora e capa ficam em branco de propósito (a capa usa o
-- desenho padrão) — dá para completar depois em Livros → Editar.
-- =========================================================

BEGIN;

-- ---------- gêneros (os 4 que já existiam + 10 novos) ----------
-- Tabela temporária usada duas vezes: para criar as categorias novas e,
-- mais abaixo, para achar a categoria de cada livro pelo slug OU pelo nome
-- (caso alguém já tenha criado, por exemplo, "Terror" com outro slug).
CREATE TEMP TABLE generos (nome text, slug text) ON COMMIT DROP;
INSERT INTO generos (nome, slug) VALUES
  ('Romance',               'romance'),
  ('Ficção científica',     'ficcao-cientifica'),
  ('Educacional',           'educacional'),
  ('Obras brasileiras',     'obras-brasileiras'),
  ('Fantasia',              'fantasia'),
  ('Suspense e mistério',   'suspense-e-misterio'),
  ('Terror',                'terror'),
  ('Distopia',              'distopia'),
  ('Poesia',                'poesia'),
  ('Infantojuvenil',        'infantojuvenil'),
  ('Biografia e memórias',  'biografia-e-memorias'),
  ('História',              'historia'),
  ('Filosofia',             'filosofia'),
  ('HQs e mangás',          'hqs-e-mangas');

INSERT INTO categorias (nome, slug)
SELECT nome, slug FROM generos
ON CONFLICT DO NOTHING;

-- ---------- livros ----------
-- (codigo, titulo, autor, slug da categoria, ano, exemplares, descricao)
INSERT INTO livros (codigo, titulo, autor, categoria_id, ano_publicacao,
                    exemplares_total, exemplares_disponiveis, descricao)
SELECT DISTINCT ON (v.codigo)
       v.codigo, v.titulo, v.autor, c.id, v.ano, v.exemplares, v.exemplares, v.descricao
FROM (VALUES
  -- Romance
  ('RO-001', 'Jane Eyre', 'Charlotte Brontë', 'romance', 1847, 2,
   'Uma órfã se torna governanta em Thornfield Hall e se apaixona pelo misterioso sr. Rochester.'),
  ('RO-002', 'O Morro dos Ventos Uivantes', 'Emily Brontë', 'romance', 1847, 2,
   'A paixão destrutiva entre Heathcliff e Catherine atravessa gerações nos pântanos ingleses.'),
  ('RO-003', 'Anna Kariênina', 'Liev Tolstói', 'romance', 1878, 1,
   'Uma aristocrata russa desafia a sociedade ao viver um romance fora do casamento.'),
  ('RO-004', 'É Assim que Acaba', 'Colleen Hoover', 'romance', 2016, 3,
   'Lily se apaixona por Ryle, mas precisa encarar um ciclo de violência que conhece bem.'),

  -- Ficção científica
  ('FC-001', 'Fundação', 'Isaac Asimov', 'ficcao-cientifica', 1951, 2,
   'Um matemático prevê a queda do Império Galáctico e cria um plano para encurtar a era de trevas.'),
  ('FC-002', 'Eu, Robô', 'Isaac Asimov', 'ficcao-cientifica', 1950, 2,
   'Contos sobre robôs guiados pelas Três Leis da Robótica e os dilemas que elas criam.'),
  ('FC-003', 'Neuromancer', 'William Gibson', 'ficcao-cientifica', 1984, 1,
   'Um hacker decadente é contratado para um último trabalho no ciberespaço. Marco do cyberpunk.'),
  ('FC-004', 'O Guia do Mochileiro das Galáxias', 'Douglas Adams', 'ficcao-cientifica', 1979, 3,
   'Minutos antes de a Terra ser demolida, Arthur Dent é resgatado para uma viagem absurda pelo universo.'),

  -- Educacional
  ('ED-001', 'Uma Breve História do Tempo', 'Stephen Hawking', 'educacional', 1988, 2,
   'Buracos negros, Big Bang e a natureza do tempo explicados para quem não é físico.'),
  ('ED-002', 'Cosmos', 'Carl Sagan', 'educacional', 1980, 2,
   'Uma viagem pela história da ciência e pelo lugar da humanidade no universo.'),
  ('ED-003', 'O Gene: Uma História Íntima', 'Siddhartha Mukherjee', 'educacional', 2016, 1,
   'A história da genética, de Mendel à edição de genes, entrelaçada com a família do autor.'),
  ('ED-004', 'Pedagogia da Autonomia', 'Paulo Freire', 'educacional', 1996, 3,
   'Reflexões sobre os saberes necessários à prática educativa e o respeito à autonomia do aluno.'),

  -- Obras brasileiras
  ('BR-001', 'Memórias Póstumas de Brás Cubas', 'Machado de Assis', 'obras-brasileiras', 1881, 3,
   'Um defunto autor narra, com ironia, a própria vida medíocre da elite carioca do século XIX.'),
  ('BR-002', 'Vidas Secas', 'Graciliano Ramos', 'obras-brasileiras', 1938, 3,
   'Uma família de retirantes enfrenta a seca e a miséria no sertão nordestino.'),
  ('BR-003', 'O Cortiço', 'Aluísio Azevedo', 'obras-brasileiras', 1890, 2,
   'A vida coletiva de um cortiço carioca retrata a ambição e a desigualdade no Brasil do século XIX.'),
  ('BR-004', 'Capitães da Areia', 'Jorge Amado', 'obras-brasileiras', 1937, 2,
   'Um grupo de meninos abandonados sobrevive nas ruas de Salvador.'),
  ('BR-005', 'A Hora da Estrela', 'Clarice Lispector', 'obras-brasileiras', 1977, 2,
   'A história de Macabéa, uma jovem nordestina invisível no Rio de Janeiro.'),
  ('BR-006', 'Grande Sertão: Veredas', 'João Guimarães Rosa', 'obras-brasileiras', 1956, 1,
   'O ex-jagunço Riobaldo relembra suas batalhas, seus pactos e seu amor por Diadorim.'),
  ('BR-007', 'Macunaíma', 'Mário de Andrade', 'obras-brasileiras', 1928, 2,
   'As aventuras do "herói sem nenhum caráter", síntese bem-humorada da identidade brasileira.'),
  ('BR-008', 'Torto Arado', 'Itamar Vieira Junior', 'obras-brasileiras', 2019, 3,
   'Duas irmãs do sertão baiano, marcadas por um acidente na infância, lutam pela terra onde vivem.'),

  -- Fantasia
  ('FA-001', 'O Hobbit', 'J. R. R. Tolkien', 'fantasia', 1937, 3,
   'Bilbo Bolseiro deixa a vida tranquila para ajudar anões a recuperar um tesouro guardado por um dragão.'),
  ('FA-002', 'O Senhor dos Anéis: A Sociedade do Anel', 'J. R. R. Tolkien', 'fantasia', 1954, 2,
   'Frodo parte numa jornada para destruir o Um Anel antes que Sauron o recupere.'),
  ('FA-003', 'Harry Potter e a Pedra Filosofal', 'J. K. Rowling', 'fantasia', 1997, 3,
   'Aos onze anos, Harry descobre que é bruxo e começa seus estudos em Hogwarts.'),
  ('FA-004', 'O Nome do Vento', 'Patrick Rothfuss', 'fantasia', 2007, 1,
   'Kvothe conta a própria história, de artista mambembe a lenda viva.'),
  ('FA-005', 'O Leão, a Feiticeira e o Guarda-Roupa', 'C. S. Lewis', 'fantasia', 1950, 2,
   'Quatro irmãos atravessam um guarda-roupa e chegam a Nárnia, dominada por um inverno sem fim.'),

  -- Suspense e mistério
  ('SM-001', 'Assassinato no Expresso do Oriente', 'Agatha Christie', 'suspense-e-misterio', 1934, 2,
   'Hercule Poirot investiga um assassinato num trem parado pela neve, onde todos parecem suspeitos.'),
  ('SM-002', 'E Não Sobrou Nenhum', 'Agatha Christie', 'suspense-e-misterio', 1939, 2,
   'Dez desconhecidos são atraídos para uma ilha e começam a morrer um a um.'),
  ('SM-003', 'Um Estudo em Vermelho', 'Arthur Conan Doyle', 'suspense-e-misterio', 1887, 2,
   'A primeira aventura de Sherlock Holmes e dr. Watson.'),
  ('SM-004', 'Garota Exemplar', 'Gillian Flynn', 'suspense-e-misterio', 2012, 2,
   'No quinto aniversário de casamento, Amy desaparece e o marido vira o principal suspeito.'),
  ('SM-005', 'O Código Da Vinci', 'Dan Brown', 'suspense-e-misterio', 2003, 2,
   'Um simbologista investiga um assassinato no Louvre e descobre uma conspiração secular.'),

  -- Terror
  ('TE-001', 'It: A Coisa', 'Stephen King', 'terror', 1986, 2,
   'Sete amigos enfrentam, na infância e de novo na vida adulta, uma entidade que aterroriza sua cidade.'),
  ('TE-002', 'O Iluminado', 'Stephen King', 'terror', 1977, 2,
   'Um escritor aceita cuidar de um hotel isolado durante o inverno, com a esposa e o filho.'),
  ('TE-003', 'Drácula', 'Bram Stoker', 'terror', 1897, 2,
   'Em cartas e diários, um grupo tenta deter o conde vampiro que chega a Londres.'),
  ('TE-004', 'Frankenstein', 'Mary Shelley', 'terror', 1818, 2,
   'Um cientista dá vida a uma criatura e foge da responsabilidade pelo que criou.'),

  -- Distopia
  ('DI-001', '1984', 'George Orwell', 'distopia', 1949, 3,
   'Sob a vigilância do Grande Irmão, Winston Smith ousa pensar diferente.'),
  ('DI-002', 'Admirável Mundo Novo', 'Aldous Huxley', 'distopia', 1932, 2,
   'Uma sociedade controlada por engenharia genética, condicionamento e prazer obrigatório.'),
  ('DI-003', 'Fahrenheit 451', 'Ray Bradbury', 'distopia', 1953, 2,
   'Num futuro em que livros são proibidos, um bombeiro encarregado de queimá-los começa a lê-los.'),
  ('DI-004', 'O Conto da Aia', 'Margaret Atwood', 'distopia', 1985, 2,
   'Num regime teocrático, Offred é uma das mulheres forçadas a gerar filhos para a elite.'),

  -- Poesia
  ('PO-001', 'Sentimento do Mundo', 'Carlos Drummond de Andrade', 'poesia', 1940, 2,
   'Poemas que unem a inquietação pessoal ao olhar sobre um mundo em guerra.'),
  ('PO-002', 'Antologia Poética', 'Vinicius de Moraes', 'poesia', 1954, 2,
   'Seleção feita pelo próprio poeta, com sonetos e poemas sobre o amor e a vida.'),
  ('PO-003', 'Mensagem', 'Fernando Pessoa', 'poesia', 1934, 1,
   'Único livro em português publicado em vida por Pessoa, sobre a história e o destino de Portugal.'),
  ('PO-004', 'Lira dos Vinte Anos', 'Álvares de Azevedo', 'poesia', 1853, 2,
   'Poemas do ultrarromantismo brasileiro, entre o sonho, o amor e a morte.'),

  -- Infantojuvenil
  ('IJ-001', 'O Pequeno Príncipe', 'Antoine de Saint-Exupéry', 'infantojuvenil', 1943, 3,
   'Um aviador perdido no deserto conhece um pequeno príncipe vindo de outro planeta.'),
  ('IJ-002', 'Reinações de Narizinho', 'Monteiro Lobato', 'infantojuvenil', 1931, 2,
   'As primeiras aventuras de Narizinho, Pedrinho e Emília no Sítio do Picapau Amarelo.'),
  ('IJ-003', 'Extraordinário', 'R. J. Palacio', 'infantojuvenil', 2012, 3,
   'Auggie, um menino com uma deformidade facial, vai à escola pela primeira vez.'),
  ('IJ-004', 'A Menina que Roubava Livros', 'Markus Zusak', 'infantojuvenil', 2005, 2,
   'Na Alemanha nazista, a Morte narra a história de Liesel e sua paixão pelos livros.'),
  ('IJ-005', 'Percy Jackson e o Ladrão de Raios', 'Rick Riordan', 'infantojuvenil', 2005, 3,
   'Percy descobre que é filho de Poseidon e é acusado de roubar o raio de Zeus.'),

  -- Biografia e memórias
  ('BI-001', 'O Diário de Anne Frank', 'Anne Frank', 'biografia-e-memorias', 1947, 3,
   'O diário de uma adolescente judia escondida com a família em Amsterdã durante a ocupação nazista.'),
  ('BI-002', 'Eu Sou Malala', 'Malala Yousafzai', 'biografia-e-memorias', 2013, 2,
   'A jovem paquistanesa que defendeu o direito das meninas à educação e sobreviveu a um atentado.'),
  ('BI-003', 'Quarto de Despejo', 'Carolina Maria de Jesus', 'biografia-e-memorias', 1960, 2,
   'O diário de uma catadora de papel na favela do Canindé, em São Paulo.'),
  ('BI-004', 'Steve Jobs', 'Walter Isaacson', 'biografia-e-memorias', 2011, 1,
   'Biografia do cofundador da Apple, baseada em dezenas de entrevistas com ele e quem o conheceu.'),

  -- História
  ('HI-001', 'Sapiens: Uma Breve História da Humanidade', 'Yuval Noah Harari', 'historia', 2011, 3,
   'Como o Homo sapiens passou a dominar o planeta, da Revolução Cognitiva aos dias de hoje.'),
  ('HI-002', '1808', 'Laurentino Gomes', 'historia', 2007, 2,
   'A fuga da corte portuguesa para o Brasil e como ela mudou a história do país.'),
  ('HI-003', 'Armas, Germes e Aço', 'Jared Diamond', 'historia', 1997, 1,
   'Por que algumas sociedades conquistaram outras? Geografia, agricultura e doenças explicam.'),

  -- Filosofia
  ('FI-001', 'O Mundo de Sofia', 'Jostein Gaarder', 'filosofia', 1991, 3,
   'Uma adolescente recebe cartas misteriosas que a levam pela história da filosofia.'),
  ('FI-002', 'Assim Falou Zaratustra', 'Friedrich Nietzsche', 'filosofia', 1883, 1,
   'Em forma poética, Zaratustra anuncia o além-do-homem e a transvaloração dos valores.'),
  ('FI-003', 'O Mito de Sísifo', 'Albert Camus', 'filosofia', 1942, 1,
   'Ensaio sobre o absurdo da existência e a pergunta sobre o sentido da vida.'),
  ('FI-004', 'O Príncipe', 'Nicolau Maquiavel', 'filosofia', 1532, 2,
   'Tratado sobre como conquistar e manter o poder, base do pensamento político moderno.'),

  -- HQs e mangás
  ('HQ-001', 'Maus', 'Art Spiegelman', 'hqs-e-mangas', 1986, 2,
   'O autor recolhe a história do pai, sobrevivente do Holocausto, com judeus retratados como ratos.'),
  ('HQ-002', 'Persépolis', 'Marjane Satrapi', 'hqs-e-mangas', 2000, 2,
   'Memórias em quadrinhos de uma menina crescendo no Irã durante e depois da Revolução Islâmica.'),
  ('HQ-003', 'Watchmen', 'Alan Moore e Dave Gibbons', 'hqs-e-mangas', 1987, 1,
   'Super-heróis aposentados investigam a morte de um antigo colega, à beira de uma guerra nuclear.'),
  ('HQ-004', 'Turma da Mônica: Laços', 'Vitor Cafaggi e Lu Cafaggi', 'hqs-e-mangas', 2013, 2,
   'Mônica, Cebolinha, Cascão e Magali partem em busca do Floquinho, que desapareceu.')
) AS v (codigo, titulo, autor, slug, ano, exemplares, descricao)
JOIN generos g    ON g.slug = v.slug
JOIN categorias c ON c.slug = g.slug OR c.nome = g.nome
WHERE NOT EXISTS (SELECT 1 FROM livros l WHERE lower(l.titulo) = lower(v.titulo))
ORDER BY v.codigo, (c.slug = g.slug) DESC   -- se achar pelos dois, prefere a do slug
ON CONFLICT DO NOTHING;

COMMIT;

-- ---------- conferência: quantos títulos por categoria ----------
SELECT c.nome AS categoria, COUNT(l.id) AS titulos
FROM categorias c
LEFT JOIN livros l ON l.categoria_id = c.id
GROUP BY c.nome
ORDER BY titulos DESC, c.nome;
