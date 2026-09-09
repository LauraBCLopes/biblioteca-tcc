<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin(); // acervo é visível para admin e aluno; ações de cadastro ficam restritas a admin abaixo

$pdo = getConexao();

// --- categorias (usadas no formulário e nos filtros) ---
$categorias = $pdo->query('SELECT id, nome, slug FROM categorias ORDER BY nome')->fetchAll();

// --- filtros vindos da URL ---
$busca          = trim($_GET['busca'] ?? '');
$categoriaSlug  = $_GET['categoria'] ?? 'todas';

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
$sql .= ' ORDER BY l.titulo';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$livros = $stmt->fetchAll();

// --- se veio ?editar=ID, carrega o livro para preencher o formulário (só admin edita) ---
$livroEditando = null;
if (isAdmin() && !empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM livros WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $livroEditando = $stmt->fetch();
}

$tituloAba = 'Acervo';
$paginaAtual = 'livros';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Ficha 02 / Acervo</div>
        <h2>Catálogo de livros</h2>
      </div>
      <div class="mono" style="color:#5a5044;font-size:12.5px;"><?= count($livros) ?> título(s) encontrado(s)</div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="form-panel">
      <h3 style="margin-bottom:16px;">
        <?= $livroEditando ? 'Editar livro' : 'Cadastrar novo livro' ?>
      </h3>
      <form method="post" action="livro_salvar.php">
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
            <label>Exemplares (total)</label>
            <input required name="exemplares_total" type="number" min="1"
                   value="<?= e((string) ($livroEditando['exemplares_total'] ?? '1')) ?>">
          </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px;">
          <button class="btn brass" type="submit"><?= $livroEditando ? 'Salvar alterações' : 'Adicionar ao acervo' ?></button>
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

    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; align-items:center;">
      <form method="get" style="display:contents;">
        <input type="hidden" name="categoria" value="<?= e($categoriaSlug) ?>">
        <input type="search" name="busca" value="<?= e($busca) ?>"
               placeholder="Buscar por título ou autor…"
               style="border:1px solid #cfc4ab;background:#fff;padding:8px 11px;border-radius:3px;font-size:13px;min-width:220px;">
        <button class="btn ghost small" type="submit">Buscar</button>
      </form>
      <div style="display:flex; gap:6px; flex-wrap:wrap;">
        <a href="?categoria=todas&busca=<?= urlencode($busca) ?>"
           class="btn <?= $categoriaSlug === 'todas' ? 'brass' : 'ghost' ?> small">Todas</a>
        <?php foreach ($categorias as $cat): ?>
          <a href="?categoria=<?= e($cat['slug']) ?>&busca=<?= urlencode($busca) ?>"
             class="btn <?= $categoriaSlug === $cat['slug'] ? 'brass' : 'ghost' ?> small"><?= e($cat['nome']) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Código</th><th>Título</th><th>Autor</th><th>Categoria</th>
            <th>Exemplares</th><th>Disponíveis</th><?php if (isAdmin()): ?><th>Ações</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$livros): ?>
            <tr><td colspan="<?= isAdmin() ? 7 : 6 ?>"><div style="padding:30px;text-align:center;color:#8a8069;">Nenhum livro encontrado.</div></td></tr>
          <?php else: foreach ($livros as $l): ?>
            <tr>
              <td class="mono" style="color:#8a8069;font-size:11.5px;"><?= e($l['codigo']) ?></td>
              <td>
                <strong><?= e($l['titulo']) ?></strong>
                <?php if ($l['ano_publicacao']): ?><span style="color:#8a8069;"> (<?= (int) $l['ano_publicacao'] ?>)</span><?php endif; ?>
              </td>
              <td><?= e($l['autor']) ?></td>
              <td><span class="badge muted"><?= e($l['categoria_nome']) ?></span></td>
              <td class="mono"><?= (int) $l['exemplares_total'] ?></td>
              <td class="mono">
                <?php if ((int) $l['exemplares_disponiveis'] === 0): ?>
                  <span class="badge alert">0 disponíveis</span>
                <?php else: ?>
                  <span class="badge ok"><?= (int) $l['exemplares_disponiveis'] ?> disponíveis</span>
                <?php endif; ?>
              </td>
              <?php if (isAdmin()):
                $confirmMsg = htmlspecialchars(
                    json_encode('Remover "' . $l['titulo'] . '" do acervo?', JSON_UNESCAPED_UNICODE),
                    ENT_QUOTES
                ); ?>
              <td style="display:flex; gap:6px; flex-wrap:wrap;">
                <a class="btn ghost small" href="?editar=<?= (int) $l['id'] ?>">Editar</a>
                <a class="btn danger small" href="livro_excluir.php?id=<?= (int) $l['id'] ?>"
                   onclick='return confirm(<?= $confirmMsg ?>);'>Excluir</a>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
