<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();

$busca = trim($_GET['busca'] ?? '');

$sql = "SELECT l.*,
        (SELECT COUNT(*) FROM emprestimos e WHERE e.leitor_id = l.id AND e.status != 'devolvido') AS emprestimos_ativos,
        (SELECT COUNT(*) FROM emprestimos e WHERE e.leitor_id = l.id AND e.status = 'atrasado') AS emprestimos_atrasados,
        (SELECT COUNT(*) FROM emprestimos e WHERE e.leitor_id = l.id AND e.status = 'ativo' AND DATEDIFF(e.data_prevista, CURDATE()) BETWEEN 0 AND 3) AS emprestimos_vencendo
        FROM leitores l
        WHERE 1=1";
$params = [];

if ($busca !== '') {
    $sql .= ' AND (l.nome LIKE :busca OR l.email LIKE :busca)';
    $params['busca'] = '%' . $busca . '%';
}
$sql .= ' ORDER BY l.nome';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leitores = $stmt->fetchAll();

// --- se veio ?editar=ID, carrega o leitor para preencher o formulário ---
$leitorEditando = null;
if (!empty($_GET['editar'])) {
    $stmt = $pdo->prepare('SELECT * FROM leitores WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['editar']]);
    $leitorEditando = $stmt->fetch();
}

$tituloAba = 'Leitores';
$paginaAtual = 'leitores';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Ficha 03 / Leitores</div>
        <h2>Usuários cadastrados</h2>
      </div>
      <div class="mono" style="color:#5a5044;font-size:12.5px;"><?= count($leitores) ?> leitor(es) encontrado(s)</div>
    </div>

    <div class="form-panel">
      <h3 style="margin-bottom:16px;">
        <?= $leitorEditando ? 'Editar leitor' : 'Cadastrar novo leitor' ?>
      </h3>
      <form method="post" action="leitor_salvar.php">
        <?php if ($leitorEditando): ?>
          <input type="hidden" name="id" value="<?= (int) $leitorEditando['id'] ?>">
        <?php endif; ?>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px,1fr)); gap:14px; align-items:end;">
          <div class="field">
            <label>Nome completo</label>
            <input required name="nome" placeholder="Ex: Maria Oliveira"
                   value="<?= e($leitorEditando['nome'] ?? '') ?>">
          </div>
          <div class="field">
            <label>E-mail</label>
            <input required type="email" name="email" placeholder="maria@email.com"
                   value="<?= e($leitorEditando['email'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Telefone</label>
            <input name="telefone" placeholder="(17) 99999-0000"
                   value="<?= e($leitorEditando['telefone'] ?? '') ?>">
          </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px;">
          <button class="btn brass" type="submit"><?= $leitorEditando ? 'Salvar alterações' : 'Cadastrar leitor' ?></button>
          <?php if ($leitorEditando): ?>
            <a href="leitores.php" class="btn ghost">Cancelar edição</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; align-items:center;">
      <form method="get" style="display:contents;">
        <input type="search" name="busca" value="<?= e($busca) ?>"
               placeholder="Buscar por nome ou e-mail…"
               style="border:1px solid #cfc4ab;background:#fff;padding:8px 11px;border-radius:3px;font-size:13px;min-width:220px;">
        <button class="btn ghost small" type="submit">Buscar</button>
        <?php if ($busca !== ''): ?><a href="leitores.php" class="btn ghost small">Limpar</a><?php endif; ?>
      </form>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Leitor</th><th>Contato</th><th>Status</th><th>Empréstimos ativos</th><th>Cadastrado em</th><th>Ações</th></tr>
        </thead>
        <tbody>
          <?php if (!$leitores): ?>
            <tr><td colspan="6"><div style="padding:30px;text-align:center;color:#8a8069;">Nenhum leitor encontrado.</div></td></tr>
          <?php else: foreach ($leitores as $u):
              $iniciais = '';
              foreach (array_slice(explode(' ', trim($u['nome'])), 0, 2) as $parte) {
                  $iniciais .= mb_strtoupper(mb_substr($parte, 0, 1));
              }
              $confirmMsg = htmlspecialchars(
                  json_encode('Remover "' . $u['nome'] . '" do cadastro de leitores?', JSON_UNESCAPED_UNICODE),
                  ENT_QUOTES
              );
          ?>
            <tr>
              <td>
                <span class="avatar-init" style="width:30px;height:30px;border-radius:50%;background:#1f3b2e;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;margin-right:8px;vertical-align:middle;"><?= e($iniciais) ?></span>
                <strong><?= e($u['nome']) ?></strong>
              </td>
              <td><?= e($u['email']) ?><br><span style="color:#8a8069;font-size:12px;"><?= e($u['telefone'] ?: '—') ?></span></td>
              <td>
                <?php if ($u['status'] === 'bloqueado'): ?>
                  <span class="badge alert">Bloqueado</span>
                <?php else: ?>
                  <span class="badge ok">Ativo</span>
                <?php endif; ?>
              </td>
              <td class="mono">
                <?= (int) $u['emprestimos_ativos'] ?>
                <?php if ((int) $u['emprestimos_atrasados'] > 0): ?>
                  <br><span class="badge alert">Atrasado</span>
                <?php elseif ((int) $u['emprestimos_vencendo'] > 0): ?>
                  <br><span class="badge warn">Vencendo</span>
                <?php endif; ?>
              </td>
              <td class="mono" style="color:#8a8069;font-size:12px;"><?= date('d/m/Y', strtotime($u['data_cadastro'])) ?></td>
              <td style="display:flex; gap:6px; flex-wrap:wrap;">
                <a class="btn ghost small" href="?editar=<?= (int) $u['id'] ?>">Editar</a>
                <a class="btn ghost small" href="leitor_bloqueio.php?id=<?= (int) $u['id'] ?>">
                  <?= $u['status'] === 'bloqueado' ? 'Desbloquear' : 'Bloquear' ?>
                </a>
                <a class="btn danger small" href="leitor_excluir.php?id=<?= (int) $u['id'] ?>"
                   onclick='return confirm(<?= $confirmMsg ?>);'>Excluir</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
