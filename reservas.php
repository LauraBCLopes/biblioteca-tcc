<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$usuario = adminAtual();

// ---------- registrar nova reserva (botão "Reservar" da página do livro) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $livroId  = (int) ($_POST['livro_id'] ?? 0);
    // aluno só pode reservar para si mesmo, mesmo que tente enviar outro leitor_id
    $leitorId = isAdmin() ? (int) ($_POST['leitor_id'] ?? 0) : (int) $usuario['id'];

    if ($livroId <= 0 || $leitorId <= 0) {
        flash('Selecione um livro e um leitor.', 'erro');
        header('Location: reservas.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT titulo FROM livros WHERE id = :id');
    $stmt->execute(['id' => $livroId]);
    $livro = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT nome, status FROM leitores WHERE id = :id");
    $stmt->execute(['id' => $leitorId]);
    $leitor = $stmt->fetch();

    if (!$livro) {
        flash('Livro não encontrado.', 'erro');
        header('Location: reservas.php');
        exit;
    }
    if (!$leitor || $leitor['status'] !== 'ativo') {
        flash('Este leitor está bloqueado e não pode fazer reservas.', 'erro');
        header('Location: reservas.php');
        exit;
    }

    // evita reserva duplicada do mesmo leitor para o mesmo livro
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas
        WHERE livro_id = :livro_id AND leitor_id = :leitor_id AND status = 'pendente'");
    $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);
    if ($stmt->fetchColumn() > 0) {
        flash('Este leitor já possui uma reserva pendente para este livro.', 'erro');
        header('Location: reservas.php');
        exit;
    }

    // evita reserva de um livro que o leitor já está com o empréstimo em aberto
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
        WHERE livro_id = :livro_id AND leitor_id = :leitor_id AND status != 'devolvido'");
    $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);
    if ($stmt->fetchColumn() > 0) {
        flash('Este leitor já está com um exemplar deste livro emprestado.', 'erro');
        header('Location: reservas.php');
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO reservas (livro_id, leitor_id, data_reserva, status)
        VALUES (:livro_id, :leitor_id, :hoje, \'pendente\')');
    $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId, 'hoje' => date('Y-m-d')]);

    flash("Reserva registrada para {$leitor['nome']}.");
    header('Location: reservas.php');
    exit;
}

// ---------- atender — só admin ----------
if (!empty($_GET['atender'])) {
    requireAdmin();

    $idAtender = (int) $_GET['atender'];

    $stmt = $pdo->prepare('SELECT * FROM reservas WHERE id = :id');
    $stmt->execute(['id' => $idAtender]);
    $reserva = $stmt->fetch();

    if (!$reserva || $reserva['status'] !== 'pendente') {
        flash('Reserva não encontrada ou já finalizada.', 'erro');
        header('Location: reservas.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT exemplares_disponiveis, titulo FROM livros WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $reserva['livro_id']]);
        $livro = $stmt->fetch();

        $stmt = $pdo->prepare('SELECT status, nome FROM leitores WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $reserva['leitor_id']]);
        $leitor = $stmt->fetch();

        if (!$livro || (int) $livro['exemplares_disponiveis'] <= 0) {
            throw new RuntimeException('Este livro não possui exemplares disponíveis no momento.');
        }
        if (!$leitor || $leitor['status'] !== 'ativo') {
            throw new RuntimeException('Este leitor está bloqueado e não pode retirar livros.');
        }

        $stmt = $pdo->prepare('UPDATE livros SET exemplares_disponiveis = exemplares_disponiveis - 1 WHERE id = :id');
        $stmt->execute(['id' => $reserva['livro_id']]);

        // datas calculadas no PHP (não em SQL) para funcionar igual em MySQL e Postgres
        $hoje = date('Y-m-d');
        $prevista = date('Y-m-d', strtotime('+14 days'));

        $stmt = $pdo->prepare('INSERT INTO emprestimos
            (livro_id, leitor_id, data_emprestimo, data_prevista, status, renovacoes)
            VALUES (:livro_id, :leitor_id, :hoje, :prevista, \'ativo\', 0)');
        $stmt->execute(['livro_id' => $reserva['livro_id'], 'leitor_id' => $reserva['leitor_id'], 'hoje' => $hoje, 'prevista' => $prevista]);

        $stmt = $pdo->prepare("UPDATE reservas SET status = 'atendida' WHERE id = :id");
        $stmt->execute(['id' => $idAtender]);

        $pdo->commit();
        flash("Reserva atendida: empréstimo de \"{$livro['titulo']}\" registrado para {$leitor['nome']}.");
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash($ex->getMessage(), 'erro');
    }

    header('Location: reservas.php');
    exit;
}

// ---------- cancelar — admin ou o próprio aluno ----------
if (!empty($_GET['cancelar'])) {
    $idCancelar = (int) $_GET['cancelar'];

    $stmt = $pdo->prepare('SELECT * FROM reservas WHERE id = :id');
    $stmt->execute(['id' => $idCancelar]);
    $reserva = $stmt->fetch();

    if (!$reserva || $reserva['status'] !== 'pendente') {
        flash('Reserva não encontrada ou já finalizada.', 'erro');
        header('Location: reservas.php');
        exit;
    }
    if (!isAdmin() && (int) $reserva['leitor_id'] !== (int) $usuario['id']) {
        flash('Você só pode cancelar as suas próprias reservas.', 'erro');
        header('Location: reservas.php');
        exit;
    }

    $stmt = $pdo->prepare("UPDATE reservas SET status = 'cancelada' WHERE id = :id");
    $stmt->execute(['id' => $idCancelar]);

    flash('Reserva cancelada.');
    header('Location: ' . (($_GET['voltar'] ?? '') === 'emprestimos' ? 'emprestimos.php?aba=reservas' : 'reservas.php'));
    exit;
}

// ---------- listagem ----------
$filtro = $_GET['status'] ?? 'pendente';

$sql = "SELECT res.*, l.titulo AS livro_titulo, l.exemplares_disponiveis, r.nome AS leitor_nome, r.turma AS leitor_turma, r.status AS leitor_status
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
        <div class="eyebrow">Reservas</div>
        <h2><?= isAdmin() ? 'Fila de reservas' : 'Minhas reservas' ?></h2>
      </div>
      <div class="mono text-muted" style="font-size:12.5px;"><?= $pendentesCount ?> pendente(s)</div>
    </div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px;">
      <?php
      $abas = ['pendente' => 'Pendentes', 'atendida' => 'Atendidas', 'cancelada' => 'Canceladas', 'todas' => 'Todas'];
      foreach ($abas as $slug => $label): ?>
        <a href="?status=<?= $slug ?>" class="btn <?= $filtro === $slug ? 'primary' : 'ghost' ?> small"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap tabela-cartoes">
      <table>
        <thead>
          <tr>
            <th>Fila</th><th>Livro</th><?php if (isAdmin()): ?><th>Leitor</th><?php endif; ?><th>Data da reserva</th>
            <th>Status</th><th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$reservas): ?>
            <tr><td colspan="<?= isAdmin() ? 6 : 5 ?>"><div style="padding:30px;text-align:center;color:var(--texto-secundario);">Nenhuma reserva nesta visualização.</div></td></tr>
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
                <?php if ($r['leitor_turma']): ?><br><span class="text-muted" style="font-size:12px;">Sala <?= e($r['leitor_turma']) ?></span><?php endif; ?>
                <?php if ($r['status'] === 'pendente' && $r['leitor_status'] !== 'ativo'): ?>
                  <br><span class="badge alert">Leitor bloqueado</span>
                <?php endif; ?>
              </td>
              <?php endif; ?>
              <td class="mono"><?= date('d/m/Y', strtotime($r['data_reserva'])) ?></td>
              <td><span class="badge <?= $sl['c'] ?>"><?= $sl['t'] ?></span></td>
              <td><div class="acoes-linha">
                <?php if ($r['status'] === 'pendente'): ?>
                  <?php if (isAdmin()): ?>
                    <?php if ($podeAtender): ?>
                      <a class="btn primary small" href="?atender=<?= (int) $r['id'] ?>">Atender</a>
                    <?php else: ?>
                      <button class="btn ghost small" disabled title="Sem exemplar disponível ou leitor bloqueado">Atender</button>
                    <?php endif; ?>
                  <?php endif; ?>
                  <a class="btn danger small" href="?cancelar=<?= (int) $r['id'] ?>"
                     data-confirmar="Cancelar a reserva de &quot;<?= e($r['livro_titulo']) ?>&quot;? A reserva sai da fila deste livro."
                     data-confirmar-titulo="Cancelar reserva"
                     data-confirmar-botao="Cancelar reserva">Cancelar</a>
                <?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
