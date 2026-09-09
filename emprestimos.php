<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$usuario = adminAtual();

$filtro = $_GET['status'] ?? 'todos';

$sql = "SELECT e.*, l.titulo AS livro_titulo, l.codigo AS livro_codigo,
               r.nome AS leitor_nome
        FROM emprestimos e
        JOIN livros l    ON l.id = e.livro_id
        JOIN leitores r  ON r.id = e.leitor_id";
$paramsTodos = [];
if (!isAdmin()) {
    $sql .= ' WHERE e.leitor_id = :leitor_id';
    $paramsTodos['leitor_id'] = $usuario['id'];
}
$sql .= ' ORDER BY e.data_emprestimo DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($paramsTodos);
$todos = $stmt->fetchAll();

// 'situacao' é calculada aqui (não em SQL) para funcionar igual em MySQL e Postgres
$hojeTs = strtotime(date('Y-m-d'));
foreach ($todos as &$e) {
    if ($e['status'] === 'devolvido') {
        $e['situacao'] = 'devolvido';
    } elseif ($e['status'] === 'atrasado') {
        $e['situacao'] = 'atrasado';
    } else {
        $dias = (int) round((strtotime($e['data_prevista']) - $hojeTs) / 86400);
        $e['situacao'] = $dias <= 3 ? 'aviso' : 'ativo';
    }
}
unset($e);

$emprestimos = $filtro === 'todos'
    ? $todos
    : array_values(array_filter($todos, function ($e) use ($filtro) {
        return $e['situacao'] === $filtro;
    }));

$emCirculacao = count(array_filter($todos, function ($e) {
    return $e['status'] !== 'devolvido';
}));

// --- opções para os selects do formulário (só usado pelo admin) ---
$livrosDisponiveis = [];
$leitoresAtivos = [];
if (isAdmin()) {
    $livrosDisponiveis = $pdo->query(
        "SELECT id, titulo, exemplares_disponiveis FROM livros WHERE exemplares_disponiveis > 0 ORDER BY titulo"
    )->fetchAll();
    $leitoresAtivos = $pdo->query(
        "SELECT id, nome FROM leitores WHERE status = 'ativo' ORDER BY nome"
    )->fetchAll();
}

$SITUACAO_LABEL = [
    'ativo'     => ['t' => 'Em dia',   'c' => 'ok'],
    'aviso'     => ['t' => 'Vencendo', 'c' => 'warn'],
    'atrasado'  => ['t' => 'Atrasado', 'c' => 'alert'],
    'devolvido' => ['t' => 'Devolvido','c' => 'muted'],
];

$tituloAba = 'Empréstimos';
$paginaAtual = 'emprestimos';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Ficha 04 / Circulação</div>
        <h2><?= isAdmin() ? 'Empréstimos e devoluções' : 'Meus empréstimos' ?></h2>
      </div>
      <div class="mono" style="color:#5a5044;font-size:12.5px;"><?= $emCirculacao ?> em circulação</div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="form-panel">
      <h3 style="margin-bottom:16px;">Registrar novo empréstimo</h3>
      <form method="post" action="emprestimo_salvar.php">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px,1fr)); gap:14px; align-items:end;">
          <div class="field">
            <label>Livro</label>
            <select required name="livro_id">
              <?php if (!$livrosDisponiveis): ?>
                <option value="" disabled selected>Nenhum exemplar disponível</option>
              <?php else: ?>
                <option value="">Selecione</option>
                <?php foreach ($livrosDisponiveis as $l): ?>
                  <option value="<?= $l['id'] ?>"><?= e($l['titulo']) ?> — <?= (int) $l['exemplares_disponiveis'] ?> disp.</option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="field">
            <label>Leitor</label>
            <select required name="leitor_id">
              <?php if (!$leitoresAtivos): ?>
                <option value="" disabled selected>Nenhum leitor ativo disponível</option>
              <?php else: ?>
                <option value="">Selecione</option>
                <?php foreach ($leitoresAtivos as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="field">
            <label>Prazo (dias)</label>
            <input name="prazo" type="number" min="1" value="14">
          </div>
        </div>
        <div style="margin-top:16px;">
          <button class="btn brass" type="submit">Confirmar empréstimo</button>
        </div>
        <div class="hint" style="text-align:left;margin-top:12px;">
          Leitores bloqueados e livros sem exemplares disponíveis não aparecem nas listas acima.
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px;">
      <?php
      $abas = ['todos' => 'Todos', 'ativo' => 'Em dia', 'aviso' => 'Vencendo', 'atrasado' => 'Atrasados', 'devolvido' => 'Devolvidos'];
      foreach ($abas as $slug => $label): ?>
        <a href="?status=<?= $slug ?>" class="btn <?= $filtro === $slug ? 'brass' : 'ghost' ?> small"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Livro</th><th>Leitor</th><th>Empréstimo</th><th>Devolução prevista</th>
            <th>Situação</th><th>Renovações</th><th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$emprestimos): ?>
            <tr><td colspan="7"><div style="padding:30px;text-align:center;color:#8a8069;">Nenhum empréstimo nesta visualização.</div></td></tr>
          <?php else: foreach ($emprestimos as $e):
              $sl = $SITUACAO_LABEL[$e['situacao']];
              $podeRenovar = !in_array($e['situacao'], ['atrasado', 'devolvido']) && (int) $e['renovacoes'] < 2;
          ?>
            <tr>
              <td><strong><?= e($e['livro_titulo']) ?></strong><br><span class="mono" style="color:#8a8069;font-size:11.5px;"><?= e($e['livro_codigo']) ?></span></td>
              <td><?= e($e['leitor_nome']) ?></td>
              <td class="mono"><?= date('d/m/Y', strtotime($e['data_emprestimo'])) ?></td>
              <td class="mono"><?= date('d/m/Y', strtotime($e['data_prevista'])) ?></td>
              <td><span class="badge <?= $sl['c'] ?>"><?= $sl['t'] ?></span></td>
              <td class="mono"><?= (int) $e['renovacoes'] ?>/2</td>
              <td style="display:flex; gap:6px; flex-wrap:wrap;">
                <?php if ($e['status'] !== 'devolvido'): ?>
                  <?php if (isAdmin()): ?>
                    <a class="btn brass small" href="emprestimo_devolver.php?id=<?= (int) $e['id'] ?>">Devolver</a>
                  <?php endif; ?>
                  <?php if ($podeRenovar): ?>
                    <a class="btn ghost small" href="emprestimo_renovar.php?id=<?= (int) $e['id'] ?>">Renovar</a>
                  <?php else: ?>
                    <button class="btn ghost small" disabled>Renovar</button>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>