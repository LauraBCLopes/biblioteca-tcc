<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();

// ---------- salvar (cadastro/edição) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id        = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $nome      = trim($_POST['nome'] ?? '');
    $usuario   = trim($_POST['usuario'] ?? '');
    $telefone  = trim($_POST['telefone'] ?? '');
    $matricula = trim($_POST['matricula'] ?? '');
    $turma     = trim($_POST['turma'] ?? '');
    $email     = montarEmailComDominio($usuario, 'aluno.com.br');

    if ($nome === '' || $usuario === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('Preencha o nome e um usuário/e-mail válido.', 'erro');
        header('Location: leitores.php' . ($id ? "?editar=$id" : ''));
        exit;
    }

    // e-mail precisa ser único
    $stmt = $pdo->prepare('SELECT id FROM leitores WHERE email = :email AND id != :id');
    $stmt->execute(['email' => $email, 'id' => $id ?? 0]);
    if ($stmt->fetch()) {
        flash('Já existe um leitor cadastrado com esse e-mail.', 'erro');
        header('Location: leitores.php' . ($id ? "?editar=$id" : ''));
        exit;
    }

    if ($id) {
        $stmt = $pdo->prepare('UPDATE leitores SET nome = :nome, email = :email, telefone = :telefone, matricula = :matricula, turma = :turma WHERE id = :id');
        $stmt->execute(['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'matricula' => $matricula ?: null, 'turma' => $turma ?: null, 'id' => $id]);
        flash('Leitor atualizado com sucesso.');
    } else {
        $stmt = $pdo->prepare('INSERT INTO leitores (nome, email, telefone, matricula, turma) VALUES (:nome, :email, :telefone, :matricula, :turma)');
        $stmt->execute(['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'matricula' => $matricula ?: null, 'turma' => $turma ?: null]);
        flash('Leitor cadastrado com sucesso.');
    }

    header('Location: leitores.php');
    exit;
}

// ---------- excluir ----------
if (!empty($_GET['excluir'])) {
    $idExcluir = (int) $_GET['excluir'];

    // Assim como nos livros, um leitor que já tomou algum empréstimo (mesmo
    // devolvido) não pode ser removido — preserva o histórico de circulação.
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM emprestimos WHERE leitor_id = :id');
    $stmt->execute(['id' => $idExcluir]);

    if ($stmt->fetchColumn() > 0) {
        flash('Este leitor possui histórico de empréstimos e não pode ser removido.', 'erro');
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM leitores WHERE id = :id');
            $stmt->execute(['id' => $idExcluir]);
            flash('Leitor removido do cadastro.');
        } catch (PDOException $e) {
            flash('Não foi possível remover este leitor (ele está referenciado em outro registro).', 'erro');
        }
    }

    header('Location: leitores.php');
    exit;
}

// ---------- bloquear/desbloquear ----------
if (!empty($_GET['bloqueio'])) {
    $idBloqueio = (int) $_GET['bloqueio'];

    $stmt = $pdo->prepare('SELECT * FROM leitores WHERE id = :id');
    $stmt->execute(['id' => $idBloqueio]);
    $leitor = $stmt->fetch();

    if (!$leitor) {
        flash('Leitor não encontrado.', 'erro');
        header('Location: leitores.php');
        exit;
    }

    if ($leitor['status'] === 'bloqueado') {
        // Só desbloqueia se não houver empréstimo atrasado em aberto
        // (a data prevista já passou e ainda não foi devolvido).
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
            WHERE leitor_id = :id AND status != 'devolvido' AND data_prevista < :hoje");
        $stmt->execute(['id' => $idBloqueio, 'hoje' => date('Y-m-d')]);

        if ($stmt->fetchColumn() > 0) {
            flash('Este leitor ainda possui empréstimo(s) atrasado(s). Registre a devolução antes de desbloquear.', 'erro');
            header('Location: leitores.php');
            exit;
        }

        $novoStatus = 'ativo';
        $msg = 'Leitor desbloqueado.';
    } else {
        $novoStatus = 'bloqueado';
        $msg = 'Leitor bloqueado manualmente.';
    }

    $stmt = $pdo->prepare('UPDATE leitores SET status = :status WHERE id = :id');
    $stmt->execute(['status' => $novoStatus, 'id' => $idBloqueio]);

    flash($msg);
    header('Location: leitores.php');
    exit;
}

// Por quanto tempo depois de redefinir a senha ainda dá para desfazer (em segundos).
const PRAZO_DESFAZER_SENHA = 600;

// ---------- resetar senha (leitor esqueceu a senha) ----------
if (!empty($_GET['resetar_senha'])) {
    $idResetar = (int) $_GET['resetar_senha'];

    $stmt = $pdo->prepare('SELECT nome, senha_hash FROM leitores WHERE id = :id');
    $stmt->execute(['id' => $idResetar]);
    $leitor = $stmt->fetch();

    if (!$leitor) {
        flash('Leitor não encontrado.', 'erro');
        header('Location: leitores.php');
        exit;
    }
    if ($leitor['senha_hash'] === null) {
        flash($leitor['nome'] . ' ainda não tem senha definida. Ele(a) pode criar uma em "Criar conta".', 'erro');
        header('Location: leitores.php');
        exit;
    }

    $stmt = $pdo->prepare('UPDATE leitores SET senha_hash = NULL WHERE id = :id');
    $stmt->execute(['id' => $idResetar]);

    // guarda a senha antiga (já criptografada) para o botão "Desfazer"
    $_SESSION['senha_removida'] = ['id' => $idResetar, 'nome' => $leitor['nome'], 'hash' => $leitor['senha_hash'], 'em' => time()];
    flash('Senha de ' . $leitor['nome'] . ' removida. O leitor pode definir uma nova em "Criar conta", com o mesmo usuário/e-mail.', 'ok',
          ['texto' => 'Desfazer', 'url' => 'leitores.php?desfazer_senha=1']);
    header('Location: leitores.php');
    exit;
}

// ---------- desfazer a última redefinição de senha ----------
if (!empty($_GET['desfazer_senha'])) {
    $removida = $_SESSION['senha_removida'] ?? null;
    unset($_SESSION['senha_removida']);

    if (!$removida || time() - $removida['em'] > PRAZO_DESFAZER_SENHA) {
        flash('Não há redefinição de senha recente para desfazer.', 'erro');
        header('Location: leitores.php');
        exit;
    }

    // só devolve a senha antiga se o leitor ainda não criou uma nova nesse meio-tempo
    $stmt = $pdo->prepare('UPDATE leitores SET senha_hash = :hash WHERE id = :id AND senha_hash IS NULL');
    $stmt->execute(['hash' => $removida['hash'], 'id' => $removida['id']]);

    if ($stmt->rowCount() > 0) {
        flash('Redefinição desfeita: ' . $removida['nome'] . ' voltou a entrar com a senha antiga.');
    } else {
        flash('Não foi possível desfazer: ' . $removida['nome'] . ' já criou uma senha nova.', 'erro');
    }
    header('Location: leitores.php');
    exit;
}

// ---------- listagem ----------
$busca = trim($_GET['busca'] ?? '');

// datas calculadas no PHP (não com DATEDIFF) para funcionar igual em MySQL e Postgres
$hoje = date('Y-m-d');
$emTresDias = date('Y-m-d', strtotime('+3 days'));

$sql = "SELECT l.*,
        (SELECT COUNT(*) FROM emprestimos e WHERE e.leitor_id = l.id AND e.status != 'devolvido') AS emprestimos_ativos,
        (SELECT COUNT(*) FROM emprestimos e WHERE e.leitor_id = l.id AND e.status = 'atrasado') AS emprestimos_atrasados,
        (SELECT COUNT(*) FROM emprestimos e WHERE e.leitor_id = l.id AND e.status = 'ativo' AND e.data_prevista BETWEEN :hoje AND :em_tres_dias) AS emprestimos_vencendo
        FROM leitores l
        WHERE 1=1";
$params = ['hoje' => $hoje, 'em_tres_dias' => $emTresDias];

if ($busca !== '') {
    // placeholders distintos: com EMULATE_PREPARES desligado o mesmo nome não pode se repetir
    $sql .= ' AND (l.nome LIKE :busca_nome OR l.email LIKE :busca_email OR l.turma LIKE :busca_turma)';
    $params['busca_nome'] = $params['busca_email'] = $params['busca_turma'] = '%' . $busca . '%';
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
        <div class="eyebrow">Usuários</div>
        <h2>Usuários cadastrados</h2>
      </div>
      <div class="mono text-muted" style="font-size:12.5px;"><?= count($leitores) ?> leitor(es) encontrado(s)</div>
    </div>

    <div class="form-panel">
      <h3 style="margin-bottom:16px;">
        <?= $leitorEditando ? 'Editar leitor' : 'Cadastrar novo leitor' ?>
      </h3>
      <form method="post" action="leitores.php">
        <?php if ($leitorEditando): ?>
          <input type="hidden" name="id" value="<?= (int) $leitorEditando['id'] ?>">
        <?php endif; ?>

        <div class="form-grid">
          <div class="field">
            <label>Nome completo</label>
            <input required name="nome" placeholder="Ex: Maria Oliveira"
                   value="<?= e($leitorEditando['nome'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Usuário</label>
            <div class="login-user-field">
              <input required type="text" name="usuario" placeholder="maria"
                     autocapitalize="off" autocorrect="off"
                     value="<?= e($leitorEditando['email'] ?? '') ?>">
              <span class="login-suffix">@aluno.com.br</span>
            </div>
          </div>
          <div class="field">
            <label>Telefone</label>
            <input name="telefone" placeholder="(17) 99999-0000"
                   value="<?= e($leitorEditando['telefone'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Matrícula (opcional)</label>
            <input name="matricula" placeholder="Ex: 2026001234"
                   value="<?= e($leitorEditando['matricula'] ?? '') ?>">
          </div>
          <div class="field">
            <label>Sala / série</label>
            <input name="turma" placeholder="Ex: 8º ano B" maxlength="30"
                   value="<?= e($leitorEditando['turma'] ?? '') ?>">
          </div>
        </div>

        <div style="margin-top:16px; display:flex; gap:10px;">
          <button class="btn primary" type="submit"><?= $leitorEditando ? 'Salvar alterações' : 'Cadastrar leitor' ?></button>
          <?php if ($leitorEditando): ?>
            <a href="leitores.php" class="btn ghost">Cancelar edição</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:16px; align-items:center;">
      <form method="get" style="display:contents;">
        <input type="search" name="busca" value="<?= e($busca) ?>"
               placeholder="Buscar por nome, e-mail ou sala…"
               style="border:1px solid var(--linha);background:var(--branco);padding:9px 13px;border-radius:var(--radius-sm);font-size:13px;min-width:220px;">
        <button class="btn ghost small" type="submit">Buscar</button>
        <?php if ($busca !== ''): ?><a href="leitores.php" class="btn ghost small">Limpar</a><?php endif; ?>
      </form>
    </div>

    <div class="table-wrap tabela-cartoes">
      <table>
        <thead>
          <tr><th>Leitor</th><th>Contato</th><th>Status</th><th>Empréstimos ativos</th><th>Cadastrado em</th><th>Ações</th></tr>
        </thead>
        <tbody>
          <?php if (!$leitores): ?>
            <tr><td colspan="6"><div style="padding:30px;text-align:center;color:var(--texto-secundario);">Nenhum leitor encontrado.</div></td></tr>
          <?php else: foreach ($leitores as $u): ?>
            <tr>
              <td>
                <span class="avatar-init sm"><?= e(iniciaisNome($u['nome'])) ?></span>
                <strong><?= e($u['nome']) ?></strong>
                <?php if ($u['turma'] || $u['matricula']): ?>
                  <br><span class="text-muted mono" style="font-size:11px;margin-left:44px;"><?= e(implode(' · ', array_filter([
                      $u['turma'] ? 'Sala ' . $u['turma'] : '',
                      $u['matricula'] ? 'Matrícula ' . $u['matricula'] : '',
                  ]))) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e($u['email']) ?><br><span class="text-muted" style="font-size:12px;"><?= e($u['telefone'] ?: '—') ?></span></td>
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
              <td class="mono" style="color:var(--texto-secundario);font-size:12px;"><?= date('d/m/Y', strtotime($u['data_cadastro'])) ?></td>
              <td style="display:flex; gap:6px; flex-wrap:wrap;">
                <a class="btn ghost small" href="?editar=<?= (int) $u['id'] ?>">Editar</a>
                <a class="btn ghost small" href="?resetar_senha=<?= (int) $u['id'] ?>"
                   data-confirmar="Remover a senha atual de &quot;<?= e($u['nome']) ?>&quot;? O leitor vai precisar definir uma nova senha em Criar conta. Você poderá desfazer logo em seguida."
                   data-confirmar-titulo="Redefinir senha"
                   data-confirmar-botao="Redefinir senha">Redefinir senha</a>
                <a class="btn ghost small" href="?bloqueio=<?= (int) $u['id'] ?>">
                  <?= $u['status'] === 'bloqueado' ? 'Desbloquear' : 'Bloquear' ?>
                </a>
                <a class="btn danger small" href="?excluir=<?= (int) $u['id'] ?>"
                   data-confirmar="Remover &quot;<?= e($u['nome']) ?>&quot; do cadastro de leitores?"
                   data-confirmar-titulo="Excluir leitor"
                   data-confirmar-botao="Excluir">Excluir</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
