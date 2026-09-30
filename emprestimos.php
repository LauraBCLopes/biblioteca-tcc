<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$usuario = adminAtual();

// ---------- registrar novo empréstimo — só admin ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

    $livroId  = (int) ($_POST['livro_id'] ?? 0);
    $leitorId = (int) ($_POST['leitor_id'] ?? 0);
    $prazo    = max(1, (int) ($_POST['prazo'] ?? 14));

    if ($livroId <= 0 || $leitorId <= 0) {
        flash('Selecione um livro e um leitor.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        // trava a linha do livro para evitar condição de corrida entre dois
        // empréstimos simultâneos do último exemplar disponível
        $stmt = $pdo->prepare('SELECT exemplares_disponiveis, titulo FROM livros WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $livroId]);
        $livro = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT status, nome FROM leitores WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $leitorId]);
        $leitor = $stmt->fetch();

        if (!$livro || (int) $livro['exemplares_disponiveis'] <= 0) {
            throw new RuntimeException('Este livro não possui exemplares disponíveis.');
        }
        if (!$leitor || $leitor['status'] !== 'ativo') {
            throw new RuntimeException('Este leitor está bloqueado e não pode retirar livros.');
        }

        $stmt = $pdo->prepare('UPDATE livros SET exemplares_disponiveis = exemplares_disponiveis - 1 WHERE id = :id');
        $stmt->execute(['id' => $livroId]);

        // datas calculadas no PHP (não em SQL) para funcionar igual em MySQL e Postgres
        $hoje = date('Y-m-d');
        $prevista = date('Y-m-d', strtotime("+{$prazo} days"));

        $stmt = $pdo->prepare('INSERT INTO emprestimos
            (livro_id, leitor_id, data_emprestimo, data_prevista, status, renovacoes)
            VALUES (:livro_id, :leitor_id, :hoje, :prevista, \'ativo\', 0)');
        $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId, 'hoje' => $hoje, 'prevista' => $prevista]);

        $pdo->commit();
        flash("Empréstimo registrado para {$leitor['nome']}.");
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash($ex->getMessage(), 'erro');
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- devolver — só admin ----------
if (!empty($_GET['devolver'])) {
    requireAdmin();

    $idDevolver = (int) $_GET['devolver'];

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idDevolver]);
    $emprestimo = $stmt->fetch();

    if (!$emprestimo || $emprestimo['status'] === 'devolvido') {
        flash('Empréstimo não encontrado ou já devolvido.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE emprestimos SET status = 'devolvido', data_devolucao = :hoje WHERE id = :id");
        $stmt->execute(['hoje' => date('Y-m-d'), 'id' => $idDevolver]);

        $stmt = $pdo->prepare('UPDATE livros
            SET exemplares_disponiveis = LEAST(exemplares_total, exemplares_disponiveis + 1)
            WHERE id = :id');
        $stmt->execute(['id' => $emprestimo['livro_id']]);

        // Se o leitor estava bloqueado e não tem mais nenhum outro atraso em aberto, desbloqueia
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
            WHERE leitor_id = :leitor_id AND id != :id AND status = 'atrasado'");
        $stmt->execute(['leitor_id' => $emprestimo['leitor_id'], 'id' => $idDevolver]);
        $outroAtraso = $stmt->fetchColumn() > 0;

        if (!$outroAtraso) {
            $stmt = $pdo->prepare("UPDATE leitores SET status = 'ativo' WHERE id = :id AND status = 'bloqueado'");
            $stmt->execute(['id' => $emprestimo['leitor_id']]);
        }

        $pdo->commit();

        // Avisa se há reserva pendente para este título (atendida manualmente em reservas.php)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE livro_id = :livro_id AND status = 'pendente'");
        $stmt->execute(['livro_id' => $emprestimo['livro_id']]);
        $temReserva = $stmt->fetchColumn() > 0;

        flash('Devolução registrada.' . ($temReserva ? ' Há uma reserva pendente para este título.' : ''), $temReserva ? 'erro' : 'ok');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('Não foi possível registrar a devolução: ' . $ex->getMessage(), 'erro');
    }

    header('Location: emprestimos.php');
    exit;
}

/** Aplica de fato a renovação (+7 dias, zera a solicitação, soma 1 no contador). */
function aplicarRenovacao(PDO $pdo, int $idRenovar, string $dataPrevistaAtual): void
{
    // nova data calculada no PHP (não em SQL) para funcionar igual em MySQL e Postgres
    $novaPrevista = date('Y-m-d', strtotime($dataPrevistaAtual . ' +7 days'));

    $stmt = $pdo->prepare('UPDATE emprestimos
        SET data_prevista = :nova_prevista, renovacoes = renovacoes + 1, renovacao_solicitada = FALSE
        WHERE id = :id');
    $stmt->execute(['nova_prevista' => $novaPrevista, 'id' => $idRenovar]);
}

/** Existe algum OUTRO leitor com reserva pendente para este livro? (bloqueia renovação) */
function temReservaConcorrente(PDO $pdo, int $livroId, int $leitorId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas
        WHERE livro_id = :livro_id AND leitor_id != :leitor_id AND status = 'pendente'");
    $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);
    return $stmt->fetchColumn() > 0;
}

// ---------- renovar — admin renova direto; aluno apenas solicita ----------
if (!empty($_GET['renovar'])) {
    $idRenovar = (int) $_GET['renovar'];

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idRenovar]);
    $emprestimo = $stmt->fetch();

    if (!$emprestimo) {
        flash('Empréstimo não encontrado.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if (!isAdmin() && (int) $emprestimo['leitor_id'] !== (int) $usuario['id']) {
        flash('Você só pode renovar os seus próprios empréstimos.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if ($emprestimo['status'] === 'devolvido') {
        flash('Este empréstimo já foi devolvido.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if ($emprestimo['status'] === 'atrasado') {
        flash('Empréstimos atrasados não podem ser renovados. Registre a devolução.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if ((int) $emprestimo['renovacoes'] >= 2) {
        flash('Limite de 2 renovações já atingido para este empréstimo.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    if (isAdmin()) {
        // administrador renova direto (já está aprovando ao fazer isso)
        aplicarRenovacao($pdo, $idRenovar, $emprestimo['data_prevista']);
        flash('Empréstimo renovado por mais 7 dias.');
    } else {
        if (temReservaConcorrente($pdo, (int) $emprestimo['livro_id'], (int) $emprestimo['leitor_id'])) {
            flash('Não é possível solicitar renovação: outro leitor está na fila de reserva deste livro.', 'erro');
        } else {
            $stmt = $pdo->prepare('UPDATE emprestimos SET renovacao_solicitada = TRUE WHERE id = :id');
            $stmt->execute(['id' => $idRenovar]);
            flash('Solicitação de renovação enviada. Aguarde a aprovação da biblioteca.');
        }
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- aprovar/recusar solicitação de renovação — só admin ----------
if (!empty($_GET['aprovar_renovacao']) || !empty($_GET['recusar_renovacao'])) {
    requireAdmin();

    $idSolicitacao = (int) ($_GET['aprovar_renovacao'] ?? $_GET['recusar_renovacao']);

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idSolicitacao]);
    $emprestimo = $stmt->fetch();

    if (!$emprestimo || !bdBool($emprestimo['renovacao_solicitada'])) {
        flash('Não há solicitação de renovação pendente para este empréstimo.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    if (!empty($_GET['aprovar_renovacao'])) {
        aplicarRenovacao($pdo, $idSolicitacao, $emprestimo['data_prevista']);
        flash('Renovação aprovada: novo prazo de mais 7 dias.');
    } else {
        $stmt = $pdo->prepare('UPDATE emprestimos SET renovacao_solicitada = FALSE WHERE id = :id');
        $stmt->execute(['id' => $idSolicitacao]);
        flash('Solicitação de renovação recusada.');
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- listagem ----------
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
        <div class="eyebrow">Empréstimos</div>
        <h2><?= isAdmin() ? 'Empréstimos e devoluções' : 'Meus empréstimos' ?></h2>
      </div>
      <div class="mono text-muted" style="font-size:12.5px;"><?= $emCirculacao ?> em circulação</div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="form-panel">
      <h3 style="margin-bottom:16px;">Registrar novo empréstimo</h3>
      <form method="post" action="emprestimos.php">
        <div class="form-grid">
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
          <button class="btn primary" type="submit">Confirmar empréstimo</button>
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
        <a href="?status=<?= $slug ?>" class="btn <?= $filtro === $slug ? 'primary' : 'ghost' ?> small"><?= $label ?></a>
      <?php endforeach; ?>
    </div>

    <div class="table-wrap tabela-cartoes">
      <table>
        <thead>
          <tr>
            <th>Livro</th><th>Leitor</th><th>Empréstimo</th><th>Devolução prevista</th>
            <th>Situação</th><th>Renovações</th><th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$emprestimos): ?>
            <tr><td colspan="7"><div style="padding:30px;text-align:center;color:var(--texto-secundario);">Nenhum empréstimo nesta visualização.</div></td></tr>
          <?php else: foreach ($emprestimos as $e):
              $sl = $SITUACAO_LABEL[$e['situacao']];
              $podeRenovar = !in_array($e['situacao'], ['atrasado', 'devolvido']) && (int) $e['renovacoes'] < 2;
          ?>
            <tr>
              <td><strong><?= e($e['livro_titulo']) ?></strong><br><span class="mono" style="color:var(--texto-secundario);font-size:11.5px;"><?= e($e['livro_codigo']) ?></span></td>
              <td><?= e($e['leitor_nome']) ?></td>
              <td class="mono"><?= date('d/m/Y', strtotime($e['data_emprestimo'])) ?></td>
              <td class="mono"><?= date('d/m/Y', strtotime($e['data_prevista'])) ?></td>
              <td><span class="badge <?= $sl['c'] ?>"><?= $sl['t'] ?></span></td>
              <td class="mono">
                <?= (int) $e['renovacoes'] ?>/2
                <?php if (bdBool($e['renovacao_solicitada'])): ?><br><span class="badge warn">Renovação pendente</span><?php endif; ?>
              </td>
              <td style="display:flex; gap:6px; flex-wrap:wrap;">
                <?php if ($e['status'] !== 'devolvido'): ?>
                  <?php if (isAdmin()): ?>
                    <a class="btn primary small" href="?devolver=<?= (int) $e['id'] ?>">Devolver</a>
                    <?php if (bdBool($e['renovacao_solicitada'])): ?>
                      <a class="btn primary small" href="?aprovar_renovacao=<?= (int) $e['id'] ?>">Aprovar renovação</a>
                      <a class="btn danger small" href="?recusar_renovacao=<?= (int) $e['id'] ?>">Recusar</a>
                    <?php elseif ($podeRenovar): ?>
                      <a class="btn ghost small" href="?renovar=<?= (int) $e['id'] ?>">Renovar</a>
                    <?php else: ?>
                      <button class="btn ghost small" disabled>Renovar</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if (bdBool($e['renovacao_solicitada'])): ?>
                      <button class="btn ghost small" disabled>Aguardando aprovação</button>
                    <?php elseif ($podeRenovar): ?>
                      <a class="btn ghost small" href="?renovar=<?= (int) $e['id'] ?>">Solicitar renovação</a>
                    <?php else: ?>
                      <button class="btn ghost small" disabled>Solicitar renovação</button>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
