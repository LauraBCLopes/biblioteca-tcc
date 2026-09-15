<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$admin = adminAtual();
$pdo = getConexao();

// datas calculadas no PHP (não com DATEDIFF) para funcionar igual em MySQL e Postgres
$hoje = date('Y-m-d');
$emTresDias = date('Y-m-d', strtotime('+3 days'));
$hojeTs = strtotime($hoje);

/** Adiciona 'dias' (dias até o vencimento) a cada linha de uma lista de empréstimos. */
function comDiasAteVencimento(array $linhas, int $hojeTs): array
{
    foreach ($linhas as &$linha) {
        $linha['dias'] = (int) round((strtotime($linha['data_prevista']) - $hojeTs) / 86400);
    }
    unset($linha);
    return $linhas;
}

if (isAdmin()) {
    // --- números gerais do acervo e dos leitores ---
    $totalLivros        = (int) $pdo->query('SELECT COUNT(*) FROM livros')->fetchColumn();
    $totalExemplares    = (int) $pdo->query('SELECT COALESCE(SUM(exemplares_total), 0) FROM livros')->fetchColumn();
    $totalDisponiveis   = (int) $pdo->query('SELECT COALESCE(SUM(exemplares_disponiveis), 0) FROM livros')->fetchColumn();
    $totalLeitores      = (int) $pdo->query('SELECT COUNT(*) FROM leitores')->fetchColumn();
    $leitoresBloqueados = (int) $pdo->query("SELECT COUNT(*) FROM leitores WHERE status = 'bloqueado'")->fetchColumn();

    // --- circulação ---
    $emprestimosAtivos    = (int) $pdo->query("SELECT COUNT(*) FROM emprestimos WHERE status IN ('ativo','atrasado')")->fetchColumn();
    $emprestimosAtrasados = (int) $pdo->query("SELECT COUNT(*) FROM emprestimos WHERE status = 'atrasado'")->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos WHERE status = 'ativo' AND data_prevista BETWEEN :hoje AND :em_tres_dias");
    $stmt->execute(['hoje' => $hoje, 'em_tres_dias' => $emTresDias]);
    $vencendoEmBreve = (int) $stmt->fetchColumn();

    $reservasPendentes = (int) $pdo->query("SELECT COUNT(*) FROM reservas WHERE status = 'pendente'")->fetchColumn();

    // --- empréstimos que pedem atenção: atrasados + vencendo nos próximos 3 dias ---
    $stmt = $pdo->prepare(
        "SELECT e.id, e.data_prevista, e.status, l.titulo AS livro_titulo, r.nome AS leitor_nome
         FROM emprestimos e
         JOIN livros l   ON l.id = e.livro_id
         JOIN leitores r ON r.id = e.leitor_id
         WHERE e.status = 'atrasado'
            OR (e.status = 'ativo' AND e.data_prevista BETWEEN :hoje AND :em_tres_dias)
         ORDER BY e.data_prevista ASC
         LIMIT 8"
    );
    $stmt->execute(['hoje' => $hoje, 'em_tres_dias' => $emTresDias]);
    $listaAtencao = comDiasAteVencimento($stmt->fetchAll(), $hojeTs);

    // --- livros por categoria (para o gráfico de barras) ---
    $porCategoria = $pdo->query(
        "SELECT c.nome, COUNT(l.id) AS qtd
         FROM categorias c
         LEFT JOIN livros l ON l.categoria_id = c.id
         GROUP BY c.id
         ORDER BY qtd DESC"
    )->fetchAll();
    $maxCategoria = max(1, ...array_column($porCategoria, 'qtd'));

    // --- top 5 livros mais emprestados ---
    $maisEmprestados = $pdo->query(
        "SELECT l.titulo, COUNT(*) AS qtd
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         GROUP BY l.id
         ORDER BY qtd DESC
         LIMIT 5"
    )->fetchAll();
} else {
    // --- números dos PRÓPRIOS empréstimos e reservas do aluno logado ---
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos WHERE leitor_id = :id AND status IN ('ativo','atrasado')");
    $stmt->execute(['id' => $admin['id']]);
    $meusEmprestimosAtivos = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos WHERE leitor_id = :id AND status = 'atrasado'");
    $stmt->execute(['id' => $admin['id']]);
    $meusAtrasados = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos WHERE leitor_id = :id AND status = 'ativo' AND data_prevista BETWEEN :hoje AND :em_tres_dias");
    $stmt->execute(['id' => $admin['id'], 'hoje' => $hoje, 'em_tres_dias' => $emTresDias]);
    $meuVencendo = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE leitor_id = :id AND status = 'pendente'");
    $stmt->execute(['id' => $admin['id']]);
    $minhasReservasPendentes = (int) $stmt->fetchColumn();

    // --- meus empréstimos que pedem atenção: atrasados + vencendo nos próximos 3 dias ---
    $stmt = $pdo->prepare(
        "SELECT e.id, e.data_prevista, e.status, l.titulo AS livro_titulo
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         WHERE e.leitor_id = :id
           AND (e.status = 'atrasado'
                OR (e.status = 'ativo' AND e.data_prevista BETWEEN :hoje AND :em_tres_dias))
         ORDER BY e.data_prevista ASC"
    );
    $stmt->execute(['id' => $admin['id'], 'hoje' => $hoje, 'em_tres_dias' => $emTresDias]);
    $minhaListaAtencao = comDiasAteVencimento($stmt->fetchAll(), $hojeTs);
}

$tituloAba = 'Painel';
$paginaAtual = 'painel';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Painel</div>
        <h2>Bem-vindo(a), <?= e($admin['nome']) ?></h2>
        <p class="text-muted" style="margin-top:6px;font-size:13.5px;">Gerencie o acervo, os empréstimos e mantenha o conhecimento sempre em movimento.</p>
      </div>
      <div class="mono text-muted" style="font-size:12.5px;">Hoje · <?= date('d/m/Y') ?></div>
    </div>

    <?php if (isAdmin()): ?>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="rotulo">Títulos no acervo</div>
        <div class="valor"><?= $totalLivros ?></div>
      </div>
      <div class="stat-card ok">
        <div class="rotulo">Exemplares disponíveis</div>
        <div class="valor"><?= $totalDisponiveis ?> <span style="font-size:16px;color:var(--texto-secundario);">/ <?= $totalExemplares ?></span></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Leitores cadastrados</div>
        <div class="valor"><?= $totalLeitores ?></div>
      </div>
      <div class="stat-card <?= $leitoresBloqueados > 0 ? 'alert' : '' ?>">
        <div class="rotulo">Leitores bloqueados</div>
        <div class="valor"><?= $leitoresBloqueados ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Empréstimos em circulação</div>
        <div class="valor"><?= $emprestimosAtivos ?></div>
      </div>
      <div class="stat-card <?= $emprestimosAtrasados > 0 ? 'alert' : '' ?>">
        <div class="rotulo">Atrasados</div>
        <div class="valor"><?= $emprestimosAtrasados ?></div>
      </div>
      <div class="stat-card <?= $vencendoEmBreve > 0 ? 'warn' : '' ?>">
        <div class="rotulo">Vencendo em até 3 dias</div>
        <div class="valor"><?= $vencendoEmBreve ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Reservas pendentes</div>
        <div class="valor"><?= $reservasPendentes ?></div>
      </div>
    </div>

    <div class="dash-grid">
      <div class="panel">
        <h3 style="margin-bottom:16px;">Empréstimos que pedem atenção</h3>
        <?php if (!$listaAtencao): ?>
          <p style="color:var(--texto-secundario);font-size:13px;">Nenhum empréstimo atrasado ou vencendo nos próximos dias. 🎉</p>
        <?php else: foreach ($listaAtencao as $item): ?>
          <div class="attention-item">
            <div>
              <strong><?= e($item['livro_titulo']) ?></strong><br>
              <span style="color:var(--texto-secundario);font-size:12px;"><?= e($item['leitor_nome']) ?></span>
            </div>
            <div style="text-align:right;">
              <?php if ($item['status'] === 'atrasado'): ?>
                <span class="badge alert">Atrasado <?= abs((int) $item['dias']) ?>d</span>
              <?php elseif ((int) $item['dias'] === 0): ?>
                <span class="badge warn">Vence hoje</span>
              <?php else: ?>
                <span class="badge warn">Vence em <?= (int) $item['dias'] ?>d</span>
              <?php endif; ?>
              <br><span class="mono" style="color:var(--texto-secundario);font-size:11px;"><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <div style="margin-top:16px;">
          <a href="emprestimos.php" class="btn ghost small">Ver todos os empréstimos</a>
        </div>
      </div>

      <div class="panel">
        <h3 style="margin-bottom:16px;">Acervo por categoria</h3>
        <?php foreach ($porCategoria as $cat): ?>
          <div class="bar-row">
            <div class="rotulo"><?= e($cat['nome']) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($cat['qtd'] / $maxCategoria) * 100) ?>%;"></div></div>
            <div class="qtd"><?= (int) $cat['qtd'] ?></div>
          </div>
        <?php endforeach; ?>

        <h3 style="margin:22px 0 16px;">Mais emprestados</h3>
        <?php if (!$maisEmprestados): ?>
          <p style="color:var(--texto-secundario);font-size:13px;">Ainda não há empréstimos registrados.</p>
        <?php else: foreach ($maisEmprestados as $i => $item): ?>
          <div class="attention-item">
            <span><strong class="mono" style="color:var(--roxo);">#<?= $i + 1 ?></strong> <?= e($item['titulo']) ?></span>
            <span class="mono" style="color:var(--texto-secundario);"><?= (int) $item['qtd'] ?>x</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <h3 style="margin:26px 0 14px;">Ações rápidas</h3>
    <div class="quick-actions">
      <a class="quick-action" href="livros.php">
        <span class="icon"><svg viewBox="0 0 20 20" fill="none"><path d="M3 4.5c2-1 4.6-1 6.5.3v9.7c-1.9-1.3-4.5-1.3-6.5-.3V4.5ZM16.5 4.5c-2-1-4.6-1-6.5.3v9.7c1.9-1.3 4.5-1.3 6.5-.3V4.5Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg></span>
        Cadastrar novo livro
      </a>
      <a class="quick-action" href="leitores.php">
        <span class="icon"><svg viewBox="0 0 20 20" fill="none"><circle cx="10" cy="6.5" r="2.6" stroke="currentColor" stroke-width="1.6"/><path d="M4 16c.6-3 3-4.5 6-4.5s5.4 1.5 6 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></span>
        Cadastrar usuário
      </a>
      <a class="quick-action" href="emprestimos.php">
        <span class="icon"><svg viewBox="0 0 20 20" fill="none"><rect x="3.5" y="5" width="13" height="10.5" rx="1.6" stroke="currentColor" stroke-width="1.5"/><path d="M3.5 8.2h13M7 5V3.6M13 5V3.6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
        Registrar empréstimo
      </a>
      <a class="quick-action" href="relatorios.php">
        <span class="icon"><svg viewBox="0 0 20 20" fill="none"><path d="M4 16V8.5M9 16V4M14 16v-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg></span>
        Ver relatórios
      </a>
    </div>

    <?php else: ?>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="rotulo">Meus empréstimos ativos</div>
        <div class="valor"><?= $meusEmprestimosAtivos ?></div>
      </div>
      <div class="stat-card <?= $meusAtrasados > 0 ? 'alert' : '' ?>">
        <div class="rotulo">Atrasados</div>
        <div class="valor"><?= $meusAtrasados ?></div>
      </div>
      <div class="stat-card <?= $meuVencendo > 0 ? 'warn' : '' ?>">
        <div class="rotulo">Vencendo em até 3 dias</div>
        <div class="valor"><?= $meuVencendo ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Minhas reservas pendentes</div>
        <div class="valor"><?= $minhasReservasPendentes ?></div>
      </div>
    </div>

    <div class="panel">
      <h3 style="margin-bottom:16px;">Seus empréstimos que pedem atenção</h3>
      <?php if (!$minhaListaAtencao): ?>
        <p style="color:var(--texto-secundario);font-size:13px;">Nenhum empréstimo seu está atrasado ou vencendo nos próximos dias. 🎉</p>
      <?php else: foreach ($minhaListaAtencao as $item): ?>
        <div class="attention-item">
          <strong><?= e($item['livro_titulo']) ?></strong>
          <div style="text-align:right;">
            <?php if ($item['status'] === 'atrasado'): ?>
              <span class="badge alert">Atrasado <?= abs((int) $item['dias']) ?>d</span>
            <?php elseif ((int) $item['dias'] === 0): ?>
              <span class="badge warn">Vence hoje</span>
            <?php else: ?>
              <span class="badge warn">Vence em <?= (int) $item['dias'] ?>d</span>
            <?php endif; ?>
            <br><span class="mono" style="color:var(--texto-secundario);font-size:11px;"><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></span>
          </div>
        </div>
      <?php endforeach; endif; ?>
      <div style="margin-top:16px; display:flex; gap:10px;">
        <a href="emprestimos.php" class="btn ghost small">Ver meus empréstimos</a>
        <a href="livros.php" class="btn ghost small">Consultar o acervo</a>
      </div>
    </div>

    <?php endif; ?>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
