<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin(); // acervo é visível para admin e aluno; ações de cadastro ficam restritas a admin abaixo

$pdo = getConexao();

/**
 * Gera um código de chamada único, ex: RO-482.7
 * baseado na sigla da categoria.
 */
function gerarCodigo(PDO $pdo, int $categoriaId): string
{
    $stmt = $pdo->prepare('SELECT slug FROM categorias WHERE id = :id');
    $stmt->execute(['id' => $categoriaId]);
    $slug = $stmt->fetchColumn();

    $prefixos = [
        'romance'            => 'RO',
        'ficcao-cientifica'  => 'FC',
        'educacional'        => 'ED',
        'obras-brasileiras'  => 'BR',
    ];
    $prefixo = $prefixos[$slug] ?? 'XX';

    do {
        $codigo = $prefixo . '-' . random_int(100, 999) . '.' . random_int(0, 9);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM livros WHERE codigo = :codigo');
        $chk->execute(['codigo' => $codigo]);
    } while ($chk->fetchColumn() > 0);

    return $codigo;
}

// ---------- salvar (cadastro/edição) — só admin ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $id              = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $titulo          = trim($_POST['titulo'] ?? '');
    $autor           = trim($_POST['autor'] ?? '');
    $categoriaId     = (int) ($_POST['categoria_id'] ?? 0);
    $anoPublicacao   = $_POST['ano_publicacao'] !== '' ? (int) $_POST['ano_publicacao'] : null;
    $editora         = trim($_POST['editora'] ?? '');
    $isbn            = trim($_POST['isbn'] ?? '');
    $descricao       = trim($_POST['descricao'] ?? '');
    $capaUrl         = trim($_POST['capa_url'] ?? '');
    $exemplaresTotal = (int) ($_POST['exemplares_total'] ?? 0);

    if ($titulo === '' || $autor === '' || $categoriaId <= 0 || $exemplaresTotal < 1) {
        flash('Preencha corretamente todos os campos obrigatórios.', 'erro');
        header('Location: livros.php' . ($id ? "?editar=$id" : ''));
        exit;
    }

    if ($id) {
        // ---------- EDIÇÃO ----------
        $stmt = $pdo->prepare('SELECT exemplares_total, exemplares_disponiveis FROM livros WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $atual = $stmt->fetch();

        if (!$atual) {
            flash('Livro não encontrado.', 'erro');
            header('Location: livros.php');
            exit;
        }

        $emprestadosAgora = $atual['exemplares_total'] - $atual['exemplares_disponiveis'];

        if ($exemplaresTotal < $emprestadosAgora) {
            flash("Não é possível definir $exemplaresTotal exemplares: há $emprestadosAgora emprestado(s) no momento.", 'erro');
            header("Location: livros.php?editar=$id");
            exit;
        }

        $novaDisponibilidade = $exemplaresTotal - $emprestadosAgora;

        $stmt = $pdo->prepare('UPDATE livros
            SET titulo = :titulo, autor = :autor, categoria_id = :categoria_id,
                ano_publicacao = :ano, editora = :editora, isbn = :isbn,
                descricao = :descricao, capa_url = :capa_url,
                exemplares_total = :total, exemplares_disponiveis = :disp
            WHERE id = :id');
        $stmt->execute([
            'titulo'      => $titulo,
            'autor'       => $autor,
            'categoria_id'=> $categoriaId,
            'ano'         => $anoPublicacao,
            'editora'     => $editora ?: null,
            'isbn'        => $isbn ?: null,
            'descricao'   => $descricao ?: null,
            'capa_url'    => $capaUrl ?: null,
            'total'       => $exemplaresTotal,
            'disp'        => $novaDisponibilidade,
            'id'          => $id,
        ]);

        flash('Livro atualizado com sucesso.');
    } else {
        // ---------- CADASTRO ----------
        $codigo = gerarCodigo($pdo, $categoriaId);

        // :total é usado duas vezes na tabela (total = disponíveis para um livro
        // novo), mas precisa de dois placeholders distintos — com prepares nativos
        // (PDO::ATTR_EMULATE_PREPARES = false), reaproveitar o mesmo nome dá erro
        // "Invalid parameter number".
        $stmt = $pdo->prepare('INSERT INTO livros
            (codigo, titulo, autor, categoria_id, ano_publicacao, editora, isbn, descricao, capa_url, exemplares_total, exemplares_disponiveis)
            VALUES (:codigo, :titulo, :autor, :categoria_id, :ano, :editora, :isbn, :descricao, :capa_url, :total, :disponiveis)');
        $stmt->execute([
            'codigo'      => $codigo,
            'titulo'      => $titulo,
            'autor'       => $autor,
            'categoria_id'=> $categoriaId,
            'ano'         => $anoPublicacao,
            'editora'     => $editora ?: null,
            'isbn'        => $isbn ?: null,
            'descricao'   => $descricao ?: null,
            'capa_url'    => $capaUrl ?: null,
            'total'       => $exemplaresTotal,
            'disponiveis' => $exemplaresTotal,
        ]);

        flash('Livro adicionado ao acervo.');
    }

    header('Location: livros.php');
    exit;
}

// ---------- excluir — só admin ----------
if (!empty($_GET['excluir'])) {
    requireAdmin();

    $idExcluir = (int) $_GET['excluir'];

    // Um livro que já foi emprestado alguma vez (mesmo devolvido) não pode ser
    // removido — isso preservaria o histórico de circulação. A constraint
    // fk_emp_livro (ON DELETE RESTRICT) garante isso no banco; aqui checamos
    // antes para dar uma mensagem amigável ao usuário.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM emprestimos WHERE livro_id = :id');
    $stmt->execute(['id' => $idExcluir]);

    if ($stmt->fetchColumn() > 0) {
        flash('Este livro possui histórico de empréstimos e não pode ser removido do acervo.', 'erro');
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM livros WHERE id = :id');
            $stmt->execute(['id' => $idExcluir]);
            flash('Livro removido do acervo.');
        } catch (PDOException $e) {
            flash('Não foi possível remover este livro (ele está referenciado em outro registro).', 'erro');
        }
    }

    header('Location: livros.php');
    exit;
}

// ---------- favoritar / desfavoritar — só aluno ----------
if (!empty($_GET['favoritar']) || !empty($_GET['desfavoritar'])) {
    requireLogin();

    if (isAdmin()) {
        flash('Favoritos são exclusivos da conta de aluno.', 'erro');
        header('Location: livros.php');
        exit;
    }

    $usuario = adminAtual();
    $livroId = (int) ($_GET['favoritar'] ?? $_GET['desfavoritar']);

    if (!empty($_GET['favoritar'])) {
        $stmt = $pdo->prepare('SELECT id FROM favoritos WHERE leitor_id = :leitor_id AND livro_id = :livro_id');
        $stmt->execute(['leitor_id' => $usuario['id'], 'livro_id' => $livroId]);
        if (!$stmt->fetch()) {
            $stmt = $pdo->prepare('INSERT INTO favoritos (leitor_id, livro_id, data_adicionado) VALUES (:leitor_id, :livro_id, :hoje)');
            $stmt->execute(['leitor_id' => $usuario['id'], 'livro_id' => $livroId, 'hoje' => date('Y-m-d H:i:s')]);
        }
        flash('Livro adicionado aos favoritos.');
    } else {
        $stmt = $pdo->prepare('DELETE FROM favoritos WHERE leitor_id = :leitor_id AND livro_id = :livro_id');
        $stmt->execute(['leitor_id' => $usuario['id'], 'livro_id' => $livroId]);
        flash('Livro removido dos favoritos.');
    }

    header('Location: livros.php?ver=' . $livroId);
    exit;
}

// ---------- detalhes de um livro (?ver=ID) — visível para admin e aluno ----------
$verId = !empty($_GET['ver']) ? (int) $_GET['ver'] : null;
$livroDetalhe = null;
$favoritado = false;
$reservaDoAluno = null;
$emprestimoAtivoDoAluno = null;

if ($verId) {
    $stmt = $pdo->prepare('SELECT l.*, c.nome AS categoria_nome, c.slug AS categoria_slug
        FROM livros l JOIN categorias c ON c.id = l.categoria_id WHERE l.id = :id');
    $stmt->execute(['id' => $verId]);
    $livroDetalhe = $stmt->fetch();

    if (!$livroDetalhe) {
        flash('Livro não encontrado.', 'erro');
        header('Location: livros.php');
        exit;
    }

    if (!isAdmin()) {
        $usuario = adminAtual();

        $stmt = $pdo->prepare('SELECT id FROM favoritos WHERE leitor_id = :leitor_id AND livro_id = :livro_id');
        $stmt->execute(['leitor_id' => $usuario['id'], 'livro_id' => $verId]);
        $favoritado = (bool) $stmt->fetch();

        $stmt = $pdo->prepare("SELECT id FROM reservas WHERE leitor_id = :leitor_id AND livro_id = :livro_id AND status = 'pendente'");
        $stmt->execute(['leitor_id' => $usuario['id'], 'livro_id' => $verId]);
        $reservaDoAluno = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT id FROM emprestimos WHERE leitor_id = :leitor_id AND livro_id = :livro_id AND status != 'devolvido'");
        $stmt->execute(['leitor_id' => $usuario['id'], 'livro_id' => $verId]);
        $emprestimoAtivoDoAluno = $stmt->fetch();
    }
}

// ---------- listagem (visível para admin e aluno) ----------

// --- categorias (usadas no formulário e nos filtros) ---
$categorias = $pdo->query('SELECT id, nome, slug FROM categorias ORDER BY nome')->fetchAll();

$livros = [];
if (!$verId) {
    // --- filtros vindos da URL ---
    $busca             = trim($_GET['busca'] ?? '');
    $categoriaSlug     = $_GET['categoria'] ?? 'todas';
    $disponibilidade   = $_GET['disponibilidade'] ?? 'todos';
    $somenteFavoritos  = !isAdmin() && !empty($_GET['favoritos']);

    // --- monta a consulta dinamicamente, sempre com prepared statements ---
    $sql = 'SELECT l.*, c.nome AS categoria_nome, c.slug AS categoria_slug
            FROM livros l
            JOIN categorias c ON c.id = l.categoria_id
            WHERE 1=1';
    $params = [];

    if ($busca !== '') {
        $sql .= ' AND (l.titulo LIKE :busca OR l.autor LIKE :busca)';
        $params['busca'] = '%' . $busca . '%';
    }
    if ($categoriaSlug !== 'todas') {
        $sql .= ' AND c.slug = :slug';
        $params['slug'] = $categoriaSlug;
    }
    if ($disponibilidade === 'disponivel') {
        $sql .= ' AND l.exemplares_disponiveis > 0';
    } elseif ($disponibilidade === 'indisponivel') {
        $sql .= ' AND l.exemplares_disponiveis = 0';
    }
    if ($somenteFavoritos) {
        $usuario = adminAtual();
        $sql .= ' AND l.id IN (SELECT livro_id FROM favoritos WHERE leitor_id = :leitor_id)';
        $params['leitor_id'] = $usuario['id'];
    }
    $sql .= ' ORDER BY l.titulo';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $livros = $stmt->fetchAll();
}

// --- se veio ?editar=ID, carrega o livro para preencher o formulário (só admin edita) ---
$livroEditando = null;
if (isAdmin() && !empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM livros WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $livroEditando = $stmt->fetch();
}

$tituloAba = $verId ? 'Detalhes do livro' : 'Acervo';
$paginaAtual = 'livros';
require __DIR__ . '/includes/layout_topo.php';
?>

    <?php if ($verId && $livroDetalhe): ?>

    <div class="view-head">
      <div>
        <div class="eyebrow"><a href="livros.php">← Voltar ao catálogo</a></div>
        <h2><?= e($livroDetalhe['titulo']) ?></h2>
      </div>
    </div>

    <div class="panel book-detail">
      <?php if ($livroDetalhe['capa_url']): ?>
        <img class="book-cover" src="<?= e($livroDetalhe['capa_url']) ?>" alt="Capa de <?= e($livroDetalhe['titulo']) ?>">
      <?php else: ?>
        <div class="book-cover-placeholder">
          <svg viewBox="0 0 20 20" fill="none"><path d="M3 4.5c2-1 4.6-1 6.5.3v9.7c-1.9-1.3-4.5-1.3-6.5-.3V4.5ZM16.5 4.5c-2-1-4.6-1-6.5.3v9.7c1.9-1.3 4.5-1.3 6.5-.3V4.5Z" stroke="currentColor" stroke-width="1.3" fill="none" stroke-linejoin="round"/></svg>
        </div>
      <?php endif; ?>

      <div>
        <p class="text-muted" style="margin:0;font-size:14px;">
          <?= e($livroDetalhe['autor']) ?><?= $livroDetalhe['editora'] ? ' · ' . e($livroDetalhe['editora']) : '' ?>
        </p>

        <div class="book-detail-meta">
          <span class="badge muted"><?= e($livroDetalhe['categoria_nome']) ?></span>
          <?php if ($livroDetalhe['ano_publicacao']): ?><span class="badge muted"><?= (int) $livroDetalhe['ano_publicacao'] ?></span><?php endif; ?>
          <?php if ($livroDetalhe['isbn']): ?><span class="badge muted mono">ISBN <?= e($livroDetalhe['isbn']) ?></span><?php endif; ?>
          <?php if ((int) $livroDetalhe['exemplares_disponiveis'] > 0): ?>
            <span class="badge ok"><?= (int) $livroDetalhe['exemplares_disponiveis'] ?> disponível(is)</span>
          <?php else: ?>
            <span class="badge alert">Sem exemplares disponíveis</span>
          <?php endif; ?>
        </div>

        <?php if ($livroDetalhe['descricao']): ?>
          <p class="book-detail-desc"><?= nl2br(e($livroDetalhe['descricao'])) ?></p>
        <?php endif; ?>

        <p class="mono text-muted" style="font-size:12px;margin-bottom:20px;">
          Código de chamada: <?= e($livroDetalhe['codigo']) ?> ·
          <?= (int) $livroDetalhe['exemplares_disponiveis'] ?>/<?= (int) $livroDetalhe['exemplares_total'] ?> exemplares
        </p>

        <div class="book-detail-actions">
          <?php if (isAdmin()): ?>
            <a class="btn primary" href="?editar=<?= (int) $livroDetalhe['id'] ?>">Editar</a>
            <a class="btn danger" href="?excluir=<?= (int) $livroDetalhe['id'] ?>"
               data-confirmar="Remover &quot;<?= e($livroDetalhe['titulo']) ?>&quot; do acervo? Essa ação não pode ser desfeita."
               data-confirmar-titulo="Excluir livro"
               data-confirmar-botao="Excluir">Excluir</a>
          <?php else: ?>
            <?php if ($emprestimoAtivoDoAluno): ?>
              <button class="btn ghost" type="button" disabled>Você já está com este livro</button>
            <?php elseif ($reservaDoAluno): ?>
              <button class="btn ghost" type="button" disabled>Reserva pendente na fila</button>
            <?php else: ?>
              <form method="post" action="reservas.php">
                <input type="hidden" name="livro_id" value="<?= (int) $livroDetalhe['id'] ?>">
                <button class="btn primary" type="submit">
                  <?= (int) $livroDetalhe['exemplares_disponiveis'] > 0 ? 'Solicitar empréstimo' : 'Reservar (entrar na fila)' ?>
                </button>
              </form>
            <?php endif; ?>
            <a class="btn ghost" href="?<?= $favoritado ? 'desfavoritar' : 'favoritar' ?>=<?= (int) $livroDetalhe['id'] ?>">
              <?= $favoritado ? '★ Remover dos favoritos' : '☆ Adicionar aos favoritos' ?>
            </a>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php else: ?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Livros</div>
        <h2>Catálogo de livros</h2>
        <p class="text-muted" style="margin-top:6px;font-size:13.5px;">Explore nosso acervo e encontre sua próxima leitura.</p>
      </div>
      <div class="mono text-muted" style="font-size:12.5px;"><?= count($livros) ?> título(s) encontrado(s)</div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="form-panel">
      <h3 style="margin-bottom:16px;">
        <?= $livroEditando ? 'Editar livro' : 'Cadastrar novo livro' ?>
      </h3>
      <form method="post" action="livros.php">
        <?php if ($livroEditando): ?>
          <input type="hidden" name="id" value="<?= (int) $livroEditando['id'] ?>">
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px,1fr)); gap:14px; align-items:end;">
          <div class="field">
            <label>Título</label>
            <input required name="titulo" placeholder="Ex: Dom Casmurro"
                   value="<?= e($livroEditando['titulo'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Autor</label>
            <input required name="autor" placeholder="Ex: Machado de Assis"
                   value="<?= e($livroEditando['autor'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Categoria</label>
            <select required name="categoria_id">
              <option value="">Selecione</option>
              <?php foreach ($categorias as $cat): ?>
                <option value="<?= $cat['id'] ?>"
                  <?= ($livroEditando && $livroEditando['categoria_id'] == $cat['id']) ? 'selected' : '' ?>>
                  <?= e($cat['nome']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label>Ano</label>
            <input name="ano_publicacao" type="number" min="0" max="2100" placeholder="1899"
                   value="<?= e((string) ($livroEditando['ano_publicacao'] ?? '')) ?>">
          </div>
          <div class="field">
            <label>Editora (opcional)</label>
            <input name="editora" placeholder="Ex: Companhia das Letras"
                   value="<?= e($livroEditando['editora'] ?? '') ?>">
          </div>
          <div class="field">
            <label>ISBN (opcional)</label>
            <input name="isbn" placeholder="Ex: 978-85-359-0277-5"
                   value="<?= e($livroEditando['isbn'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Capa — URL da imagem (opcional)</label>
            <input name="capa_url" type="url" placeholder="https://…"
                   value="<?= e($livroEditando['capa_url'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Exemplares (total)</label>
            <input required name="exemplares_total" type="number" min="1"
                   value="<?= e((string) ($livroEditando['exemplares_total'] ?? '1')) ?>">
          </div>
        </div>

        <div class="field" style="margin-top:14px;">
          <label>Descrição (opcional)</label>
          <textarea name="descricao" rows="3" placeholder="Resumo/sinopse do livro"
                    style="border:1px solid var(--linha);background:var(--branco);padding:10px 12px;border-radius:var(--radius-sm);font-size:14px;font-family:'Inter',sans-serif;color:var(--texto);width:100%;resize:vertical;"><?= e($livroEditando['descricao'] ?? '') ?></textarea>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px;">
          <button class="btn primary" type="submit"><?= $livroEditando ? 'Salvar alterações' : 'Adicionar ao acervo' ?></button>
          <?php if ($livroEditando): ?>
            <a href="livros.php" class="btn ghost">Cancelar edição</a>
          <?php endif; ?>
        </div>
        <?php if ($livroEditando): ?>
          <div class="hint" style="text-align:left;margin-top:12px;">
            Código de chamada: <strong class="mono"><?= e($livroEditando['codigo']) ?></strong> (não muda ao editar).
            Se reduzir o total abaixo da quantidade já emprestada, o sistema vai bloquear o salvamento.
          </div>
        <?php endif; ?>
      </form>
    </div>
    <?php endif; ?>

    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; align-items:center;">
      <form method="get" style="display:contents;">
        <input type="hidden" name="categoria" value="<?= e($categoriaSlug) ?>">
        <input type="hidden" name="disponibilidade" value="<?= e($disponibilidade) ?>">
        <?php if ($somenteFavoritos): ?><input type="hidden" name="favoritos" value="1"><?php endif; ?>
        <input type="search" name="busca" value="<?= e($busca) ?>"
               placeholder="Buscar por título ou autor…"
               style="border:1px solid var(--linha);background:var(--branco);padding:9px 13px;border-radius:var(--radius-sm);font-size:13px;min-width:220px;">
        <button class="btn ghost small" type="submit">Buscar</button>
      </form>
      <?php if (!isAdmin()): ?>
        <a href="?categoria=<?= e($categoriaSlug) ?>&busca=<?= urlencode($busca) ?>&disponibilidade=<?= e($disponibilidade) ?>&favoritos=<?= $somenteFavoritos ? '0' : '1' ?>"
           class="btn <?= $somenteFavoritos ? 'primary' : 'ghost' ?> small">★ Meus favoritos</a>
      <?php endif; ?>
    </div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px;">
      <?php foreach (['todas' => 'Todas as categorias'] + array_column($categorias, 'nome', 'slug') as $slug => $label): ?>
        <a href="?categoria=<?= e($slug) ?>&busca=<?= urlencode($busca) ?>&disponibilidade=<?= e($disponibilidade) ?>&favoritos=<?= $somenteFavoritos ? '1' : '0' ?>"
           class="btn <?= $categoriaSlug === $slug ? 'primary' : 'ghost' ?> small"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px;">
      <?php $abasDisp = ['todos' => 'Qualquer disponibilidade', 'disponivel' => 'Disponível', 'indisponivel' => 'Indisponível']; foreach ($abasDisp as $slug => $label): ?>
        <a href="?categoria=<?= e($categoriaSlug) ?>&busca=<?= urlencode($busca) ?>&disponibilidade=<?= e($slug) ?>&favoritos=<?= $somenteFavoritos ? '1' : '0' ?>"
           class="btn <?= $disponibilidade === $slug ? 'primary' : 'ghost' ?> small"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <?php if (!$livros): ?>
      <div class="panel" style="text-align:center;color:var(--texto-secundario);">Nenhum livro encontrado com esses filtros.</div>
    <?php else: ?>
      <div class="book-grid">
        <?php foreach ($livros as $l): ?>
          <div class="book-card">
            <?php if ($l['capa_url']): ?>
              <img class="book-cover" src="<?= e($l['capa_url']) ?>" alt="Capa de <?= e($l['titulo']) ?>">
            <?php else: ?>
              <div class="book-cover-placeholder">
                <svg viewBox="0 0 20 20" fill="none"><path d="M3 4.5c2-1 4.6-1 6.5.3v9.7c-1.9-1.3-4.5-1.3-6.5-.3V4.5ZM16.5 4.5c-2-1-4.6-1-6.5.3v9.7c1.9-1.3 4.5-1.3 6.5-.3V4.5Z" stroke="currentColor" stroke-width="1.3" fill="none" stroke-linejoin="round"/></svg>
              </div>
            <?php endif; ?>
            <div class="book-card-body">
              <h4><?= e($l['titulo']) ?></h4>
              <div class="autor"><?= e($l['autor']) ?></div>
              <span class="badge muted" style="align-self:flex-start;"><?= e($l['categoria_nome']) ?></span>
              <div class="rodape-card">
                <?php if ((int) $l['exemplares_disponiveis'] === 0): ?>
                  <span class="badge alert">Indisponível</span>
                <?php else: ?>
                  <span class="badge ok"><?= (int) $l['exemplares_disponiveis'] ?> disp.</span>
                <?php endif; ?>
                <a class="btn ghost small" href="?ver=<?= (int) $l['id'] ?>">Ver detalhes</a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php endif; ?>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
