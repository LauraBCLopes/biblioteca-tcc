<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$admin = adminAtual();
$pdo = getConexao();

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
    $vencendoEmBreve      = (int) $pdo->query("SELECT COUNT(*) FROM emprestimos WHERE status = 'ativo' AND DATEDIFF(data_prevista, CURDATE()) BETWEEN 0 AND 3")->fetchColumn();
    $reservasPendentes    = (int) $pdo->query("SELECT COUNT(*) FROM reservas WHERE status = 'pendente'")->fetchColumn();

    // --- empréstimos que pedem atenção: atrasados + vencendo nos próximos 3 dias ---
    $listaAtencao = $pdo->query(
        "SELECT e.id, e.data_prevista, e.status, l.titulo AS livro_titulo, r.nome AS leitor_nome,
                DATEDIFF(e.data_prevista, CURDATE()) AS dias
         FROM emprestimos e
         JOIN livros l   ON l.id = e.livro_id
         JOIN leitores r ON r.id = e.leitor_id
         WHERE e.status = 'atrasado'
            OR (e.status = 'ativo' AND DATEDIFF(e.data_prevista, CURDATE()) BETWEEN 0 AND 3)
         ORDER BY e.data_prevista ASC
         LIMIT 8"
    )->fetchAll();

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

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos WHERE leitor_id = :id AND status = 'ativo' AND DATEDIFF(data_prevista, CURDATE()) BETWEEN 0 AND 3");
    $stmt->execute(['id' => $admin['id']]);
    $meuVencendo = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE leitor_id = :id AND status = 'pendente'");
    $stmt->execute(['id' => $admin['id']]);
    $minhasReservasPendentes = (int) $stmt->fetchColumn();

    // --- meus empréstimos que pedem atenção: atrasados + vencendo nos próximos 3 dias ---
    $stmt = $pdo->prepare(
        "SELECT e.id, e.data_prevista, e.status, l.titulo AS livro_titulo,
                DATEDIFF(e.data_prevista, CURDATE()) AS dias
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         WHERE e.leitor_id = :id
           AND (e.status = 'atrasado'
                OR (e.status = 'ativo' AND DATEDIFF(e.data_prevista, CURDATE()) BETWEEN 0 AND 3))
         ORDER BY e.data_prevista ASC"
    );
    $stmt->execute(['id' => $admin['id']]);
    $minhaListaAtencao = $stmt->fetchAll();
}

$tituloAba = 'Painel';
$paginaAtual = 'painel';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Ficha 01 / Painel</div>
        <h2>Bem-vindo(a), <?= e($admin['nome']) ?></h2>
      </div>
      <div class="mono" style="color:#5a5044;font-size:12.5px;">Hoje · <?= date('d/m/Y') ?></div>
    </div>

    <?php if (isAdmin()): ?>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="rotulo">Títulos no acervo</div>
        <div class="valor"><?= $totalLivros ?></div>
      </div>
      <div class="stat-card ok">
        <div class="rotulo">Exemplares disponíveis</div>
        <div class="valor"><?= $totalDisponiveis ?> <span style="font-size:16px;color:#8a8069;">/ <?= $totalExemplares ?></span></div>
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
          <p style="color:#8a8069;font-size:13px;">Nenhum empréstimo atrasado ou vencendo nos próximos dias. 🎉</p>
        <?php else: foreach ($listaAtencao as $item): ?>
          <div class="attention-item">
            <div>
              <strong><?= e($item['livro_titulo']) ?></strong><br>
              <span style="color:#8a8069;font-size:12px;"><?= e($item['leitor_nome']) ?></span>
            </div>
            <div style="text-align:right;">
              <?php if ($item['status'] === 'atrasado'): ?>
                <span class="badge alert">Atrasado <?= abs((int) $item['dias']) ?>d</span>
              <?php elseif ((int) $item['dias'] === 0): ?>
                <span class="badge warn">Vence hoje</span>
              <?php else: ?>
                <span class="badge warn">Vence em <?= (int) $item['dias'] ?>d</span>
              <?php endif; ?>
              <br><span class="mono" style="color:#8a8069;font-size:11px;"><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></span>
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
          <p style="color:#8a8069;font-size:13px;">Ainda não há empréstimos registrados.</p>
        <?php else: foreach ($maisEmprestados as $i => $item): ?>
          <div class="attention-item">
            <span><strong class="mono" style="color:#b08d57;">#<?= $i + 1 ?></strong> <?= e($item['titulo']) ?></span>
            <span class="mono" style="color:#8a8069;"><?= (int) $item['qtd'] ?>x</span>
          </div>
        <?php endforeach; endif; ?>
      </div>
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
        <p style="color:#8a8069;font-size:13px;">Nenhum empréstimo seu está atrasado ou vencendo nos próximos dias. 🎉</p>
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
            <br><span class="mono" style="color:#8a8069;font-size:11px;"><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></span>
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
