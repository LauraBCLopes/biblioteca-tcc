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

    // --- top 5 livros mais emprestados (com autor e capa para o ranking) ---
    $maisEmprestados = $pdo->query(
        "SELECT l.id, l.titulo, l.autor, l.capa_url, COUNT(*) AS qtd
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         GROUP BY l.id, l.titulo, l.autor, l.capa_url
         ORDER BY qtd DESC, l.titulo ASC
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

    // --- empréstimos recentes (tabela do topo do painel) ---
    $emprestimosRecentes = $pdo->query(
        "SELECT e.id, e.data_emprestimo, e.data_prevista, e.data_devolucao, e.status,
                l.titulo AS livro_titulo, r.nome AS leitor_nome
         FROM emprestimos e
         JOIN livros l   ON l.id = e.livro_id
         JOIN leitores r ON r.id = e.leitor_id
         ORDER BY e.data_emprestimo DESC, e.id DESC
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

    // --- livros que estão com o aluno agora (com botão para avisar a devolução) ---
    $stmt = $pdo->prepare(
        "SELECT e.id, e.data_prevista, e.status, e.devolucao_solicitada, l.titulo AS livro_titulo
         FROM emprestimos e
         JOIN livros l ON l.id = e.livro_id
         WHERE e.leitor_id = :id AND e.status != 'devolvido'
         ORDER BY e.data_prevista ASC"
    );
    $stmt->execute(['id' => $admin['id']]);
    $meusLivrosComigo = comDiasAteVencimento($stmt->fetchAll(), $hojeTs);

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

    <?php
    $primeiroNome = explode(' ', trim($admin['nome']))[0];
    // Ícones dos cards de indicadores (traço, 20x20, herdam a cor do card)
    $icones = [
        'livro'    => '<path d="M4.5 3.5h9a2 2 0 0 1 2 2v11h-11a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2Z"/><path d="M6.5 3.5v13M15.5 13.5h-11"/>',
        'pessoas'  => '<circle cx="7.5" cy="7" r="2.6"/><path d="M2.8 16c.5-2.8 2.4-4.2 4.7-4.2s4.2 1.4 4.7 4.2"/><circle cx="14" cy="7.8" r="2"/><path d="M13.4 11.8c2 .1 3.4 1.3 3.8 3.6"/>',
        'troca'    => '<path d="M4 7h11.5l-3-3M16 13H4.5l3 3"/>',
        'relogio'  => '<circle cx="10" cy="10" r="6.8"/><path d="M10 6.4V10l2.6 1.6"/>',
        'agenda'   => '<rect x="3.5" y="4.5" width="13" height="12" rx="2"/><path d="M3.5 8.2h13M7 3v3M13 3v3"/>',
        'fila'     => '<path d="M5 5h10M5 10h10M5 15h6"/>',
        'renovar'  => '<path d="M15.5 8A6 6 0 0 0 4.7 6.5M4.5 12a6 6 0 0 0 10.8 1.5"/><path d="M4.5 3.5v3h3M15.5 16.5v-3h-3"/>',
        'bloqueio' => '<circle cx="10" cy="10" r="6.8"/><path d="M5.2 14.8l9.6-9.6"/>',
    ];
    $icone = function (string $nome) use ($icones): string {
        return '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $icones[$nome] . '</svg>';
    };
    ?>

    <section class="hero">
      <div class="hero-texto">
        <div class="hero-data mono">Hoje · <?= date('d/m/Y') ?></div>
        <h2>Bem-vindo(a), <?= e($primeiroNome) ?>!</h2>
        <p><?= isAdmin()
              ? 'Gerencie o acervo, os empréstimos e mantenha o conhecimento sempre em movimento.'
              : 'Acompanhe seus empréstimos e reservas e descubra sua próxima leitura.' ?></p>
      </div>
<svg class="hero-arte" viewBox="0 0 230 130" fill="none" aria-hidden="true">
        <rect x="20" y="104" width="190" height="22" rx="4" fill="#3F7FE8"/>
        <rect x="54" y="108" width="150" height="14" rx="2" fill="#fff"/>
        <path d="M58 113h142M58 117h142" stroke="#3F7FE8" stroke-opacity=".35"/>
        <rect x="28" y="109" width="3" height="12" rx="1.5" fill="#fff" fill-opacity=".55"/>
        <rect x="30" y="80" width="176" height="22" rx="4" fill="#6C63E8"/>
        <rect x="64" y="84" width="136" height="14" rx="2" fill="#fff"/>
        <path d="M68 89h128M68 93h128" stroke="#6C63E8" stroke-opacity=".35"/>
        <rect x="38" y="85" width="3" height="12" rx="1.5" fill="#fff" fill-opacity=".55"/>
        <rect x="12" y="56" width="186" height="22" rx="4" fill="#4F6FE6"/>
        <rect x="46" y="60" width="146" height="14" rx="2" fill="#fff"/>
        <path d="M50 65h138M50 69h138" stroke="#4F6FE6" stroke-opacity=".35"/>
        <rect x="20" y="61" width="3" height="12" rx="1.5" fill="#fff" fill-opacity=".55"/>
        <rect x="34" y="32" width="166" height="22" rx="4" fill="#8B7CF6"/>
        <rect x="68" y="36" width="126" height="14" rx="2" fill="#fff"/>
        <path d="M72 41h118M72 45h118" stroke="#8B7CF6" stroke-opacity=".35"/>
        <rect x="42" y="37" width="3" height="12" rx="1.5" fill="#fff" fill-opacity=".55"/>
        <rect x="22" y="8" width="178" height="22" rx="4" fill="#5B5FE0"/>
        <rect x="56" y="12" width="138" height="14" rx="2" fill="#fff"/>
        <path d="M60 17h130M60 21h130" stroke="#5B5FE0" stroke-opacity=".35"/>
        <rect x="30" y="13" width="3" height="12" rx="1.5" fill="#fff" fill-opacity=".55"/>
      </svg>
      <div class="hero-frase">
        Ler é construir novos amanhãs.
        <span class="traco" aria-hidden="true"></span>
      </div>
    </section>

    <?php if (isAdmin()): ?>

    <div class="kpi-grid">
      <?php foreach ([
          ['Títulos no acervo',          $totalLivros,          'livro',    'roxo'],
          ['Leitores cadastrados',       $totalLeitores,        'pessoas',  'azul'],
          ['Empréstimos em circulação',  $emprestimosAtivos,    'troca',    'roxo'],
          ['Atrasados',                  $emprestimosAtrasados, 'relogio',  $emprestimosAtrasados > 0 ? 'alerta' : 'ok'],
          ['Vencendo em até 3 dias',     $vencendoEmBreve,      'agenda',   $vencendoEmBreve > 0 ? 'aviso' : 'azul'],
          ['Reservas pendentes',         $reservasPendentes,    'fila',     'azul'],
          ['Renovações aguardando',      $renovacoesPendentes,  'renovar',  $renovacoesPendentes > 0 ? 'aviso' : 'roxo'],
          ['Leitores bloqueados',        $leitoresBloqueados,   'bloqueio', $leitoresBloqueados > 0 ? 'alerta' : 'azul'],
      ] as [$rotulo, $valor, $nomeIcone, $cor]): ?>
        <div class="kpi-card <?= $cor ?>">
          <span class="kpi-icone"><?= $icone($nomeIcone) ?></span>
          <div>
            <div class="rotulo"><?= $rotulo ?></div>
            <div class="valor"><?= (int) $valor ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="dash-row">
      <div class="panel span-8">
        <div class="panel-head">
          <h3>Empréstimos recentes</h3>
          <a href="emprestimos.php">Ver todos</a>
        </div>
        <?php if (!$emprestimosRecentes): ?>
          <p class="vazio">Ainda não há empréstimos registrados.</p>
        <?php else: ?>
          <div class="tabela-painel">
            <table>
              <thead>
                <tr><th>Leitor</th><th>Livro</th><th>Empréstimo</th><th>Devolução</th><th>Situação</th></tr>
              </thead>
              <tbody>
                <?php foreach ($emprestimosRecentes as $item): ?>
                  <tr>
                    <td><?= e($item['leitor_nome']) ?></td>
                    <td><?= e($item['livro_titulo']) ?></td>
                    <td class="mono"><?= date('d/m/Y', strtotime($item['data_emprestimo'])) ?></td>
                    <td class="mono"><?= date('d/m/Y', strtotime($item['data_devolucao'] ?: $item['data_prevista'])) ?></td>
                    <td>
                      <?php if ($item['status'] === 'atrasado'): ?>
                        <span class="badge alert">Atrasado</span>
                      <?php elseif ($item['status'] === 'devolvido'): ?>
                        <span class="badge ok">Devolvido</span>
                      <?php else: ?>
                        <span class="badge azul">Em andamento</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel span-4">
        <div class="panel-head">
          <h3>Livros mais emprestados</h3>
          <a href="relatorios.php">Ver todos</a>
        </div>
        <?php if (!$maisEmprestados): ?>
          <p class="vazio">Ainda não há empréstimos registrados.</p>
        <?php else: foreach ($maisEmprestados as $i => $item): ?>
          <a class="rank-item" href="livros.php?ver=<?= (int) $item['id'] ?>">
            <span class="rank-pos"><?= $i + 1 ?></span>
            <?php if ($item['capa_url']): ?>
              <img class="rank-capa" src="<?= e($item['capa_url']) ?>" alt="">
            <?php else: ?>
              <span class="rank-capa sem-capa" aria-hidden="true"><?= $icone('livro') ?></span>
            <?php endif; ?>
            <span class="rank-texto">
              <strong><?= e($item['titulo']) ?></strong>
              <small><?= e($item['autor']) ?></small>
            </span>
            <span class="rank-qtd"><?= (int) $item['qtd'] ?> <?= (int) $item['qtd'] === 1 ? 'empréstimo' : 'empréstimos' ?></span>
          </a>
        <?php endforeach; endif; ?>
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

      <div class="panel span-8">
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
    </div>

    <div class="secao-titulo">Ações rápidas</div>
    <div class="quick-actions">
      <?php
      $seta = '<svg class="qa-seta" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8 5l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
      foreach ([
          ['livros.php',      'livro',   'Cadastrar novo livro',  'Adicione um título ao acervo'],
          ['leitores.php',    'pessoas', 'Cadastrar usuário',     'Registre um novo leitor'],
          ['emprestimos.php', 'troca',   'Registrar empréstimo',  'Realize um novo empréstimo'],
          ['relatorios.php',  'agenda',  'Ver relatórios',        'Acesse as estatísticas'],
      ] as [$link, $nomeIcone, $titulo, $descricao]): ?>
        <a class="quick-action" href="<?= $link ?>">
          <span class="icon"><?= $icone($nomeIcone) ?></span>
          <span class="qa-texto"><strong><?= $titulo ?></strong><small><?= $descricao ?></small></span>
          <?= $seta ?>
        </a>
      <?php endforeach; ?>
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
      <h3 style="margin-bottom:16px;">Livros que estão com você</h3>
      <?php if (!$meusLivrosComigo): ?>
        <p style="color:var(--texto-secundario);font-size:13px;">Você não está com nenhum livro emprestado no momento.</p>
      <?php else: foreach ($meusLivrosComigo as $item): ?>
        <div class="attention-item">
          <span>
            <strong><?= e($item['livro_titulo']) ?></strong><br>
            <?php if ($item['status'] === 'atrasado'): ?>
              <span class="badge alert">Atrasado <?= abs((int) $item['dias']) ?>d</span>
            <?php elseif ((int) $item['dias'] === 0): ?>
              <span class="badge warn">Vence hoje</span>
            <?php elseif ((int) $item['dias'] <= 3): ?>
              <span class="badge warn">Vence em <?= (int) $item['dias'] ?>d</span>
            <?php else: ?>
              <span class="badge ok">Em dia</span>
            <?php endif; ?>
            <span class="mono" style="color:var(--texto-secundario);font-size:11px;">até <?= date('d/m/Y', strtotime($item['data_prevista'])) ?></span>
          </span>
          <?php if (bdBool($item['devolucao_solicitada'])): ?>
            <span class="badge warn">Devolução aguardando confirmação</span>
          <?php else: ?>
            <a class="btn primary small" href="emprestimos.php?avisar_devolucao=<?= (int) $item['id'] ?>&amp;voltar=inicio"
               data-confirmar="Avisar a biblioteca que você devolveu &quot;<?= e($item['livro_titulo']) ?>&quot;? O empréstimo só é encerrado depois que a biblioteca confirmar o recebimento."
               data-confirmar-titulo="Devolver livro"
               data-confirmar-botao="Avisar devolução">Devolver</a>
          <?php endif; ?>
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
