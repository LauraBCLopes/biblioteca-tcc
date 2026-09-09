<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$usuario = adminAtual();

$filtro = $_GET['status'] ?? 'pendente';

$sql = "SELECT res.*, l.titulo AS livro_titulo, l.exemplares_disponiveis, r.nome AS leitor_nome, r.status AS leitor_status
        FROM reservas res
        JOIN livros l   ON l.id = res.livro_id
        JOIN leitores r ON r.id = res.leitor_id
        ORDER BY res.livro_id, res.data_reserva ASC, res.id ASC";
$todas = $pdo->query($sql)->fetchAll();

// --- calcula a posição de cada reserva pendente na fila do respectivo livro ---
$posicaoNaFila = [];
foreach ($todas as &$r) {
    if ($r['status'] !== 'pendente') {
        continue;
    }
    $livroId = $r['livro_id'];
    $posicaoNaFila[$livroId] = ($posicaoNaFila[$livroId] ?? 0) + 1;
    $r['posicao'] = $posicaoNaFila[$livroId];
}
unset($r);

// aluno só vê as próprias reservas; admin vê a fila inteira
$minhas = isAdmin()
    ? $todas
    : array_values(array_filter($todas, function ($r) use ($usuario) {
        return (int) $r['leitor_id'] === (int) $usuario['id'];
    }));

$reservas = $filtro === 'todas'
    ? $minhas
    : array_values(array_filter($minhas, function ($r) use ($filtro) {
        return $r['status'] === $filtro;
    }));

// ordena a visualização por data mais recente primeiro (mantendo a posição já calculada)
usort($reservas, function ($a, $b) {
    return strtotime($b['data_reserva']) <=> strtotime($a['data_reserva']);
});

$pendentesCount = count(array_filter($minhas, function ($r) {
    return $r['status'] === 'pendente';
}));

// --- opções para o formulário ---
$livros = $pdo->query('SELECT id, titulo, exemplares_disponiveis FROM livros ORDER BY titulo')->fetchAll();
$leitoresAtivos = [];
if (isAdmin()) {
    $leitoresAtivos = $pdo->query("SELECT id, nome FROM leitores WHERE status = 'ativo' ORDER BY nome")->fetchAll();
}

$STATUS_LABEL = [
    'pendente' => ['t' => 'Pendente', 'c' => 'warn'],
    'atendida' => ['t' => 'Atendida', 'c' => 'ok'],
    'cancelada'=> ['t' => 'Cancelada', 'c' => 'muted'],
];

$tituloAba = 'Reservas';
$paginaAtual = 'reservas';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Ficha 05 / Reservas</div>
        <h2><?= isAdmin() ? 'Fila de reservas' : 'Minhas reservas' ?></h2>
      </div>
      <div class="mono" style="color:#5a5044;font-size:12.5px;"><?= $pendentesCount ?> pendente(s)</div>
    </div>

    <div class="form-panel">
      <h3 style="margin-bottom:16px;">Registrar nova reserva</h3>
      <form method="post" action="reserva_salvar.php">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(190px,1fr)); gap:14px; align-items:end;">
          <div class="field">
            <label>Livro</label>
            <select required name="livro_id">
              <option value="">Selecione</option>
              <?php foreach ($livros as $l): ?>
                <option value="<?= $l['id'] ?>">
                  <?= e($l['titulo']) ?> — <?= (int) $l['exemplares_disponiveis'] ?> disp.
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php if (isAdmin()): ?>
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
          <?php endif; ?>
        </div>
        <div style="margin-top:16px;">
          <button class="btn brass" type="submit">Registrar reserva</button>
        </div>
        <?php if (isAdmin()): ?>
        <div class="hint" style="text-align:left;margin-top:12px;">
          Reservas ficam em fila por livro (ordem de chegada). Quando houver exemplar disponível,
          use "Atender" para converter a reserva em empréstimo automaticamente.
        </div>
        <?php else: ?>
        <div class="hint" style="text-align:left;margin-top:12px;">
          Sua reserva entra na fila do livro escolhido. Quando um exemplar ficar disponível para você,
          a biblioteca fará a entrega e registrará o empréstimo.
        </div>
        <?php endif; ?>
      </form>
    </div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px;">
      <?php
      $abas = ['pendente' => 'Pendentes', 'atendida' => 'Atendidas', 'cancelada' => 'Canceladas', 'todas' => 'Todas'];
      foreach ($abas as $slug => $label): ?>
        <a href="?status=<?= $slug ?>" class="btn <?= $filtro === $slug ? 'brass' : 'ghost' ?> small"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Fila</th><th>Livro</th><?php if (isAdmin()): ?><th>Leitor</th><?php endif; ?><th>Data da reserva</th>
            <th>Status</th><th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$reservas): ?>
            <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>"><div style="padding:30px;text-align:center;color:#8a8069;">Nenhuma reserva nesta visualização.</div></td></tr>
          <?php else: foreach ($reservas as $r):
              $sl = $STATUS_LABEL[$r['status']];
              $podeAtender = $r['status'] === 'pendente'
                  && (int) $r['exemplares_disponiveis'] > 0
                  && $r['leitor_status'] === 'ativo';
          ?>
            <tr>
              <td class="mono"><?= $r['status'] === 'pendente' ? '#' . (int) $r['posicao'] : '—' ?></td>
              <td><strong><?= e($r['livro_titulo']) ?></strong></td>
              <?php if (isAdmin()): ?>
              <td>
                <?= e($r['leitor_nome']) ?>
                <?php if ($r['status'] === 'pendente' && $r['leitor_status'] !== 'ativo'): ?>
                  <br><span class="badge alert">Leitor bloqueado</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td class="mono"><?= date('d/m/Y', strtotime($r['data_reserva'])) ?></td>
              <td><span class="badge <?= $sl['c'] ?>"><?= $sl['t'] ?></span></td>
              <td style="display:flex; gap:6px; flex-wrap:wrap;">
                <?php if ($r['status'] === 'pendente'): ?>
                  <?php if (isAdmin()): ?>
                    <?php if ($podeAtender): ?>
                      <a class="btn brass small" href="reserva_atender.php?id=<?= (int) $r['id'] ?>">Atender</a>
                    <?php else: ?>
                      <button class="btn ghost small" disabled title="Sem exemplar disponível ou leitor bloqueado">Atender</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <a class="btn danger small" href="reserva_cancelar.php?id=<?= (int) $r['id'] ?>"
                     onclick="return confirm('Cancelar esta reserva?');">Cancelar</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
