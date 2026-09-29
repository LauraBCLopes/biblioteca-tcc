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
         LIMIT 5"
    );
    $stmt->execute(['hoje' => $hoje, 'em_tres_dias' => $emTresDias]);
    $listaAtencao = comDiasAteVencimento($stmt->fetchAll(), $hojeTs);

    // --- livros por categoria (para o gráfico de barras) ---
    $porCategoria = $pdo->query(
        "SELECT c.nome, COUNT(l.id) AS qtd
         FROM categorias c
         LEFT JOIN livros l ON l.categoria_id = c.id
         GROUP BY c.id
         ORDER BY qtd DESC, c.nome ASC"
    )->fetchAll();
    $porCategoria = agruparOutras($porCategoria);
    // escala só pelos gêneros reais: a linha "Outras" não tem barra
    $maxCategoria = max(array_merge([1], array_map('intval', array_column(array_filter($porCategoria, function ($c) { return !isset($c['detalhe']); }), 'qtd'))));

    // --- top 5 livros mais emprestados ---
    $maisEmprestados = $pdo->query(
        "SELECT l.titulo, COUNT(*) AS qtd
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         GROUP BY l.id
         ORDER BY qtd DESC
         LIMIT 5"
    )->fetchAll();

    $renovacoesPendentes = (int) $pdo->query('SELECT COUNT(*) FROM emprestimos WHERE renovacao_solicitada = TRUE')->fetchColumn();

    // --- ocupação do acervo: quanto dos exemplares está fora da estante ---
    $exemplaresFora    = $totalExemplares - $totalDisponiveis;
    $percentualOcupado = $totalExemplares > 0 ? (int) round(($exemplaresFora / $totalExemplares) * 100) : 0;
    $titulosEsgotados  = (int) $pdo->query('SELECT COUNT(*) FROM livros WHERE exemplares_total > 0 AND exemplares_disponiveis = 0')->fetchColumn();
    $nuncaEmprestados  = (int) $pdo->query('SELECT COUNT(*) FROM livros l WHERE NOT EXISTS (SELECT 1 FROM emprestimos e WHERE e.livro_id = l.id)')->fetchColumn();
    $leitoresComAtraso = (int) $pdo->query("SELECT COUNT(DISTINCT leitor_id) FROM emprestimos WHERE status = 'atrasado'")->fetchColumn();

    // --- situação dos empréstimos em circulação (ativos já inclui os atrasados) ---
    $circulacaoNoPrazo = max(0, $emprestimosAtivos - $emprestimosAtrasados - $vencendoEmBreve);

    // --- movimentação dos últimos 6 meses (mês atual incluído) ---
    $mesesPainel = movimentoPorMes($pdo, date('Y-m-01', strtotime('first day of -5 months')), $hoje);

    // --- demanda reprimida: títulos com fila de reserva ---
    $filaReservas = $pdo->query(
        "SELECT l.id, l.titulo, l.exemplares_disponiveis, l.exemplares_total, COUNT(*) AS fila
         FROM reservas r
         JOIN livros l ON l.id = r.livro_id
         WHERE r.status = 'pendente'
         GROUP BY l.id, l.titulo, l.exemplares_disponiveis, l.exemplares_total
         ORDER BY fila DESC, l.titulo ASC
         LIMIT 5"
    )->fetchAll();

    // --- atividade recente: últimos empréstimos e devoluções, juntados no PHP ---
    $atividade = [];
    $ultimosEmprestimos = $pdo->query(
        "SELECT e.id, e.data_emprestimo AS data, l.titulo AS livro_titulo, r.nome AS leitor_nome
         FROM emprestimos e
         JOIN livros l   ON l.id = e.livro_id
         JOIN leitores r ON r.id = e.leitor_id
         ORDER BY e.data_emprestimo DESC, e.id DESC
         LIMIT 6"
    )->fetchAll();
    foreach ($ultimosEmprestimos as $linha) {
        $atividade[] = $linha + ['tipo' => 'emprestimo'];
    }
    $ultimasDevolucoes = $pdo->query(
        "SELECT e.id, e.data_devolucao AS data, l.titulo AS livro_titulo, r.nome AS leitor_nome
         FROM emprestimos e
         JOIN livros l   ON l.id = e.livro_id
         JOIN leitores r ON r.id = e.leitor_id
         WHERE e.data_devolucao IS NOT NULL
         ORDER BY e.data_devolucao DESC, e.id DESC
         LIMIT 6"
    )->fetchAll();
    foreach ($ultimasDevolucoes as $linha) {
        $atividade[] = $linha + ['tipo' => 'devolucao'];
    }
    usort($atividade, function ($a, $b) {
        return [$b['data'], $b['id']] <=> [$a['data'], $a['id']];
    });
    $atividade = array_slice($atividade, 0, 5);
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

    // --- meu histórico de leitura (empréstimos já devolvidos) ---
    $stmt = $pdo->prepare("SELECT data_devolucao, data_prevista FROM emprestimos WHERE leitor_id = :id AND data_devolucao IS NOT NULL");
    $stmt->execute(['id' => $admin['id']]);
    $meusDevolvidos = $stmt->fetchAll();
    $inicioAno = date('Y-01-01');
    $lidosEsteAno = 0;
    $devolvidosNoPrazo = 0;
    foreach ($meusDevolvidos as $linha) {
        if ($linha['data_devolucao'] >= $inicioAno) {
            $lidosEsteAno++;
        }
        if ($linha['data_devolucao'] <= $linha['data_prevista']) {
            $devolvidosNoPrazo++;
        }
    }
    $livrosLidos = count($meusDevolvidos);
    $meuPercentualNoPrazo = $livrosLidos > 0 ? (int) round(($devolvidosNoPrazo / $livrosLidos) * 100) : null;

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM favoritos WHERE leitor_id = :id');
    $stmt->execute(['id' => $admin['id']]);
    $meusFavoritos = (int) $stmt->fetchColumn();

    // --- o que eu mais leio, por categoria ---
    $stmt = $pdo->prepare(
        "SELECT c.id, c.nome, COUNT(*) AS qtd
         FROM emprestimos e
         JOIN livros l     ON l.id = e.livro_id
         JOIN categorias c ON c.id = l.categoria_id
         WHERE e.leitor_id = :id
         GROUP BY c.id, c.nome
         ORDER BY qtd DESC, c.nome ASC"
    );
    $stmt->execute(['id' => $admin['id']]);
    $minhasCategorias = $stmt->fetchAll();
    $maxMinhaCategoria = $minhasCategorias ? max(1, ...array_map('intval', array_column($minhasCategorias, 'qtd'))) : 1;

    // --- últimos livros que devolvi ---
    $stmt = $pdo->prepare(
        "SELECT l.id, l.titulo, l.autor, e.data_devolucao
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         WHERE e.leitor_id = :id AND e.data_devolucao IS NOT NULL
         ORDER BY e.data_devolucao DESC, e.id DESC
         LIMIT 5"
    );
    $stmt->execute(['id' => $admin['id']]);
    $meuHistorico = $stmt->fetchAll();

    // --- sugestões: títulos disponíveis e populares que ainda não peguei,
    //     priorizando a categoria que mais leio; se nunca li ou já esgotei
    //     essa categoria, sugere do acervo todo ---
    $buscarSugestoes = function (?int $categoriaId) use ($pdo, $admin): array {
        $stmt = $pdo->prepare(
            "SELECT l.id, l.titulo, l.autor, COUNT(e.id) AS popularidade
             FROM livros l
             LEFT JOIN emprestimos e ON e.livro_id = l.id
             WHERE l.exemplares_disponiveis > 0
               " . ($categoriaId ? 'AND l.categoria_id = :categoria' : '') . "
               AND NOT EXISTS (SELECT 1 FROM emprestimos m WHERE m.livro_id = l.id AND m.leitor_id = :id)
             GROUP BY l.id, l.titulo, l.autor
             ORDER BY popularidade DESC, l.titulo ASC
             LIMIT 4"
        );
        $params = ['id' => $admin['id']];
        if ($categoriaId) {
            $params['categoria'] = $categoriaId;
        }
        $stmt->execute($params);
        return $stmt->fetchAll();
    };
    $categoriaSugerida = $minhasCategorias ? $minhasCategorias[0] : null;
    $sugestoes = $categoriaSugerida ? $buscarSugestoes((int) $categoriaSugerida['id']) : [];
    if (!$sugestoes) {
        $categoriaSugerida = null;
        $sugestoes = $buscarSugestoes(null);
    }
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
      <div class="stat-card">
        <div class="rotulo">Leitores cadastrados</div>
        <div class="valor"><?= $totalLeitores ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Empréstimos em circulação</div>
        <div class="valor"><?= $emprestimosAtivos ?></div>
      </div>
      <div class="stat-card <?= $emprestimosAtrasados > 0 ? 'alert' : 'ok' ?>">
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
      <div class="stat-card <?= $renovacoesPendentes > 0 ? 'warn' : '' ?>">
        <div class="rotulo">Renovações aguardando</div>
        <div class="valor"><?= $renovacoesPendentes ?></div>
      </div>
      <div class="stat-card <?= $leitoresBloqueados > 0 ? 'alert' : '' ?>">
        <div class="rotulo">Leitores bloqueados</div>
        <div class="valor"><?= $leitoresBloqueados ?></div>
      </div>
    </div>

    <!-- ===== 1. O que precisa de ação hoje ===== -->
    <div class="secao-titulo">Precisa de atenção</div>
    <div class="dash-row">
      <div class="panel span-4">
        <h3>Vencimentos e atrasos</h3>
        <?php if (!$listaAtencao): ?>
          <p class="vazio">Nenhum empréstimo atrasado ou vencendo nos próximos dias.</p>
        <?php else: foreach ($listaAtencao as $item): ?>
          <div class="attention-item">
            <div>
              <strong><?= e($item['livro_titulo']) ?></strong><br>
              <span class="text-muted" style="font-size:12px;"><?= e($item['leitor_nome']) ?></span>
            </div>
            <div style="text-align:right;">
              <?php if ($item['status'] === 'atrasado'): ?>
                <span class="badge alert">Atrasado <?= abs((int) $item['dias']) ?>d</span>
              <?php elseif ((int) $item['dias'] === 0): ?>
                <span class="badge warn">Vence hoje</span>
              <?php else: ?>
                <span class="badge warn">Vence em <?= (int) $item['dias'] ?>d</span>
              <?php endif; ?>
              <br><span class="mono text-muted" style="font-size:11px;"><?= date('d/m/Y', strtotime($item['data_prevista'])) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <div class="panel-rodape">
          <a href="emprestimos.php" class="btn ghost small">Ver empréstimos</a>
        </div>
      </div>

      <div class="panel span-4">
        <h3>Fila de reservas</h3>
        <?php if (!$filaReservas): ?>
          <p class="vazio">Nenhum título com reserva pendente.</p>
        <?php else: foreach ($filaReservas as $item): ?>
          <div class="attention-item">
            <div>
              <strong><?= e($item['titulo']) ?></strong><br>
              <span class="text-muted" style="font-size:12px;"><?= (int) $item['exemplares_disponiveis'] ?> de <?= (int) $item['exemplares_total'] ?> disponíveis</span>
            </div>
            <span class="badge <?= (int) $item['exemplares_disponiveis'] === 0 ? 'warn' : 'muted' ?>"><?= (int) $item['fila'] ?> na fila</span>
          </div>
        <?php endforeach; endif; ?>
        <div class="panel-rodape">
          <a href="reservas.php" class="btn ghost small">Ver reservas</a>
        </div>
      </div>

      <div class="panel span-4">
        <h3>Atividade recente</h3>
        <?php if (!$atividade): ?>
          <p class="vazio">Ainda não há movimentação registrada.</p>
        <?php else: foreach ($atividade as $item): ?>
          <div class="attention-item">
            <div>
              <strong><?= e($item['livro_titulo']) ?></strong><br>
              <span class="text-muted" style="font-size:12px;"><?= e($item['leitor_nome']) ?></span>
            </div>
            <div style="text-align:right;">
              <?php if ($item['tipo'] === 'emprestimo'): ?>
                <span class="badge muted">Emprestado</span>
              <?php else: ?>
                <span class="badge ok">Devolvido</span>
              <?php endif; ?>
              <br><span class="mono text-muted" style="font-size:11px;"><?= date('d/m/Y', strtotime($item['data'])) ?></span>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ===== 2. Como a circulação está evoluindo ===== -->
    <div class="secao-titulo">Circulação</div>
    <div class="dash-row">
      <div class="panel span-8">
        <h3>Movimentação dos últimos 6 meses</h3>
        <?php if (array_sum(array_map('array_sum', $mesesPainel)) === 0): ?>
          <p class="vazio">Nenhum empréstimo ou devolução nos últimos 6 meses.</p>
        <?php else: ?>
          <?= graficoMovimento($mesesPainel) ?>
        <?php endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Situação dos empréstimos</h3>
        <?php if ($emprestimosAtivos === 0): ?>
          <p class="vazio">Nenhum livro emprestado no momento.</p>
        <?php else: ?>
          <div class="meter-valor"><?= $emprestimosAtivos ?> <small>em circulação</small></div>
          <div class="stack" style="margin-top:12px;" role="img" aria-label="<?= e("No prazo: $circulacaoNoPrazo, vencendo em até 3 dias: $vencendoEmBreve, atrasados: $emprestimosAtrasados") ?>">
            <?php foreach ([[$circulacaoNoPrazo, 'var(--ok)'], [$vencendoEmBreve, 'var(--aviso)'], [$emprestimosAtrasados, 'var(--alerta)']] as [$qtd, $cor]): if ($qtd > 0): ?>
              <div style="width: <?= round(($qtd / $emprestimosAtivos) * 100, 1) ?>%; background: <?= $cor ?>;"></div>
            <?php endif; endforeach; ?>
          </div>
          <?php foreach ([['No prazo', $circulacaoNoPrazo, 'var(--ok)'], ['Vencendo em até 3 dias', $vencendoEmBreve, 'var(--aviso)'], ['Atrasados', $emprestimosAtrasados, 'var(--alerta)']] as [$nome, $qtd, $cor]): ?>
            <div class="attention-item">
              <span style="display:inline-flex;align-items:center;gap:8px;"><i style="width:10px;height:10px;border-radius:3px;background:<?= $cor ?>;display:inline-block;"></i><?= $nome ?></span>
              <strong class="mono"><?= $qtd ?></strong>
            </div>
          <?php endforeach; ?>
          <div class="attention-item">
            <span class="text-muted">Leitores com atraso</span>
            <strong class="mono"><?= $leitoresComAtraso ?></strong>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ===== 3. Acervo ===== -->
    <div class="secao-titulo">Acervo</div>
    <div class="dash-row">
      <div class="panel span-4">
        <h3>Ocupação do acervo</h3>
        <div class="meter-valor"><?= $percentualOcupado ?>% <small>emprestado</small></div>
        <div class="bar-track" style="margin-top:10px;" role="img" aria-label="<?= $exemplaresFora ?> de <?= $totalExemplares ?> exemplares emprestados">
          <div class="bar-fill" style="width: <?= $percentualOcupado ?>%;"></div>
        </div>
        <p class="text-muted" style="font-size:12px;margin:8px 0 0;"><?= $totalDisponiveis ?> de <?= $totalExemplares ?> exemplares disponíveis na estante</p>
        <div class="mini-stats">
          <div><strong><?= $titulosEsgotados ?></strong>títulos esgotados</div>
          <div><strong><?= $nuncaEmprestados ?></strong>nunca emprestados</div>
        </div>
      </div>

      <div class="panel span-4">
        <h3>Títulos por categoria</h3>
        <?php foreach ($porCategoria as $cat): ?>
          <div class="bar-row">
            <div class="rotulo"<?= isset($cat['detalhe']) ? ' title="' . e($cat['detalhe']) . '"' : '' ?>><?= e($cat['nome']) ?></div>
            <?php if (isset($cat['detalhe'])): ?>
              <div class="text-muted" style="flex:1;font-size:12px;white-space:nowrap;">demais gêneros</div>
            <?php else: ?>
              <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($cat['qtd'] / $maxCategoria) * 100) ?>%;"></div></div>
            <?php endif; ?>
            <div class="qtd"><?= (int) $cat['qtd'] ?></div>
          </div>
        <?php endforeach; ?>
        <div class="panel-rodape">
          <a href="livros.php" class="btn ghost small">Ver acervo</a>
        </div>
      </div>

      <div class="panel span-4">
        <h3>Mais emprestados</h3>
        <?php if (!$maisEmprestados): ?>
          <p class="vazio">Ainda não há empréstimos registrados.</p>
        <?php else: foreach ($maisEmprestados as $i => $item): ?>
          <div class="attention-item">
            <span><strong class="mono" style="color:var(--roxo);">#<?= $i + 1 ?></strong> <?= e($item['titulo']) ?></span>
            <span class="mono text-muted"><?= (int) $item['qtd'] ?>x</span>
          </div>
        <?php endforeach; endif; ?>
        <div class="panel-rodape">
          <a href="relatorios.php" class="btn ghost small">Ver relatórios</a>
        </div>
      </div>
    </div>

    <div class="secao-titulo">Ações rápidas</div>
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
        <p style="color:var(--texto-secundario);font-size:13px;">Nenhum empréstimo seu está atrasado ou vencendo nos próximos dias.</p>
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

    <div class="secao-titulo">Minha jornada de leitura</div>
    <div class="stat-grid">
      <div class="stat-card">
        <div class="rotulo">Livros lidos</div>
        <div class="valor"><?= $livrosLidos ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Lidos em <?= date('Y') ?></div>
        <div class="valor"><?= $lidosEsteAno ?></div>
      </div>
      <div class="stat-card <?= $meuPercentualNoPrazo !== null && $meuPercentualNoPrazo >= 80 ? 'ok' : ($meuPercentualNoPrazo !== null ? 'warn' : '') ?>">
        <div class="rotulo">Devolvidos no prazo</div>
        <div class="valor">
          <?= $meuPercentualNoPrazo === null ? '—' : $meuPercentualNoPrazo . '<span style="font-size:16px;color:var(--texto-secundario);">%</span>' ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Meus favoritos</div>
        <div class="valor"><?= $meusFavoritos ?></div>
      </div>
    </div>

    <div class="dash-row">
      <div class="panel span-4">
        <h3>O que eu mais leio</h3>
        <?php if (!$minhasCategorias): ?>
          <p class="text-muted" style="font-size:13px;">Você ainda não pegou nenhum livro emprestado.</p>
        <?php else: foreach ($minhasCategorias as $cat): ?>
          <div class="bar-row">
            <div class="rotulo"><?= e($cat['nome']) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($cat['qtd'] / $maxMinhaCategoria) * 100) ?>%;"></div></div>
            <div class="qtd"><?= (int) $cat['qtd'] ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Lidos recentemente</h3>
        <?php if (!$meuHistorico): ?>
          <p class="text-muted" style="font-size:13px;">Os livros que você devolver aparecem aqui.</p>
        <?php else: foreach ($meuHistorico as $item): ?>
          <div class="attention-item">
            <span>
              <a href="livros.php?ver=<?= (int) $item['id'] ?>"><strong><?= e($item['titulo']) ?></strong></a><br>
              <span class="text-muted" style="font-size:12px;"><?= e($item['autor']) ?></span>
            </span>
            <span class="mono text-muted" style="font-size:11px;"><?= date('d/m/Y', strtotime($item['data_devolucao'])) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Sugestões para você</h3>
        <p class="sub">
          <?= $categoriaSugerida
              ? 'Títulos disponíveis de ' . e($categoriaSugerida['nome']) . ', a categoria que você mais lê, que você ainda não pegou.'
              : 'Os títulos disponíveis mais procurados do acervo que você ainda não pegou.' ?>
        </p>
        <?php if (!$sugestoes): ?>
          <p class="text-muted" style="font-size:13px;">Nenhuma sugestão no momento — você já leu tudo o que está disponível por aqui!</p>
        <?php else: foreach ($sugestoes as $item): ?>
          <div class="attention-item">
            <span>
              <a href="livros.php?ver=<?= (int) $item['id'] ?>"><strong><?= e($item['titulo']) ?></strong></a><br>
              <span class="text-muted" style="font-size:12px;"><?= e($item['autor']) ?></span>
            </span>
            <span class="badge ok">Disponível</span>
          </div>
        <?php endforeach; endif; ?>
        <div class="panel-rodape">
          <a href="livros.php" class="btn ghost small">Explorar o acervo</a>
        </div>
      </div>
    </div>

    <?php endif; ?>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
