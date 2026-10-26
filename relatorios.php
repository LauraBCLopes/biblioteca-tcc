<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();

// ---------- período do relatório ----------
/** Confere se a string é uma data válida no formato AAAA-MM-DD. */
function dataValida(?string $valor): bool
{
    if (!$valor) {
        return false;
    }
    $d = DateTime::createFromFormat('Y-m-d', $valor);
    return $d && $d->format('Y-m-d') === $valor;
}

$PERIODOS = [
    '30'          => 'Últimos 30 dias',
    '90'          => 'Últimos 90 dias',
    '365'         => 'Últimos 12 meses',
    'personalizado' => 'Personalizado',
];

$periodo = isset($_GET['periodo']) && isset($PERIODOS[$_GET['periodo']]) ? $_GET['periodo'] : '30';
$hoje = date('Y-m-d');

if ($periodo === 'personalizado' && dataValida($_GET['de'] ?? null) && dataValida($_GET['ate'] ?? null)) {
    $de  = $_GET['de'];
    $ate = $_GET['ate'];
    if ($de > $ate) {          // datas invertidas: troca, em vez de devolver relatório vazio
        $troca = $de; $de = $ate; $ate = $troca;
    }
} else {
    if ($periodo === 'personalizado') {
        $periodo = '30';       // datas inválidas/ausentes: volta para o padrão
    }
    $de  = date('Y-m-d', strtotime('-' . ((int) $periodo - 1) . ' days'));
    $ate = $hoje;
}

// data_cadastro é DATETIME: precisa cobrir o dia inteiro na comparação
$deInicio = $de . ' 00:00:00';
$ateFim   = $ate . ' 23:59:59';
$intervalo = ['de' => $de, 'ate' => $ate];

/** Roda uma consulta com parâmetros e devolve todas as linhas. */
function consultar(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Roda uma consulta de contagem e devolve o número. */
function contar(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Maior valor da coluna 'qtd' (mínimo 1), usado para dimensionar as barras. */
function maiorQtd(array $linhas): int
{
    $valores = array_map('intval', array_column($linhas, 'qtd'));
    return $valores ? max(max($valores), 1) : 1;
}

// ---------- dados dos relatórios em PDF ----------
// O PDF é montado no navegador (assets/js/relatorios-pdf.js, com a jsPDF);
// aqui só saem os dados em JSON. Empréstimos em aberto e atrasos são a
// situação de HOJE; os totais por livro e por leitor contam tudo o que foi
// emprestado desde o início até a data "Até" do período escolhido.
if (($_GET['dados_pdf'] ?? '') === '1') {
    $hojeTs = strtotime($hoje);
    $diasAtraso = function (string $prevista) use ($hojeTs): int {
        return max(0, (int) round(($hojeTs - strtotime($prevista)) / 86400));
    };
    $dataBr = function (?string $data): string {
        return $data ? date('d/m/Y', strtotime($data)) : '';
    };

    $abertos = [];
    foreach (consultar($pdo,
        "SELECT e.data_emprestimo, e.data_prevista, e.status, e.renovacoes,
                l.codigo AS livro_codigo, l.titulo AS livro_titulo,
                r.nome AS leitor_nome, r.email AS leitor_email, r.turma AS leitor_turma
           FROM emprestimos e
           JOIN livros l   ON l.id = e.livro_id
           JOIN leitores r ON r.id = e.leitor_id
          WHERE e.status != 'devolvido'
          ORDER BY e.data_prevista ASC, l.titulo ASC") as $linha) {
        $atrasado = $linha['status'] === 'atrasado';
        $abertos[] = [
            'livro'      => $linha['livro_titulo'],
            'codigo'     => $linha['livro_codigo'] ?? '',
            'leitor'     => $linha['leitor_nome'],
            'contato'    => $linha['leitor_email'],
            'turma'      => $linha['leitor_turma'] ?? '',
            'emprestimo' => $dataBr($linha['data_emprestimo']),
            'prazo'      => $dataBr($linha['data_prevista']),
            'renovacoes' => (int) $linha['renovacoes'],
            'atrasado'   => $atrasado,
            'dias'       => $atrasado ? $diasAtraso($linha['data_prevista']) : 0,
        ];
    }

    $porLivro = [];
    foreach (consultar($pdo,
        "SELECT l.titulo, l.autor, l.codigo, COUNT(e.id) AS total,
                SUM(CASE WHEN e.status != 'devolvido' THEN 1 ELSE 0 END) AS abertos
           FROM livros l
           JOIN emprestimos e ON e.livro_id = l.id AND e.data_emprestimo <= :ate
          GROUP BY l.id, l.titulo, l.autor, l.codigo
          ORDER BY total DESC, l.titulo ASC", ['ate' => $ate]) as $linha) {
        $porLivro[] = [
            'livro'   => $linha['titulo'],
            'autor'   => $linha['autor'],
            'codigo'  => $linha['codigo'] ?? '',
            'total'   => (int) $linha['total'],
            'abertos' => (int) $linha['abertos'],
        ];
    }

    $porLeitor = [];
    foreach (consultar($pdo,
        "SELECT r.nome, r.email, r.turma, r.status,
                COUNT(e.id) AS total,
                SUM(CASE WHEN e.status != 'devolvido' THEN 1 ELSE 0 END) AS abertos,
                SUM(CASE WHEN e.status = 'atrasado' THEN 1 ELSE 0 END) AS atrasados
           FROM leitores r
           LEFT JOIN emprestimos e ON e.leitor_id = r.id AND e.data_emprestimo <= :ate
          GROUP BY r.id, r.nome, r.email, r.turma, r.status
          ORDER BY total DESC, r.nome ASC", ['ate' => $ate]) as $linha) {
        $porLeitor[] = [
            'leitor'    => $linha['nome'],
            'contato'   => $linha['email'],
            'turma'     => $linha['turma'] ?? '',
            'bloqueado' => $linha['status'] === 'bloqueado',
            'total'     => (int) $linha['total'],
            'abertos'   => (int) $linha['abertos'],
            'atrasados' => (int) $linha['atrasados'],
        ];
    }

    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'gerado_em'    => date('d/m/Y \à\s H:i'),
        'emitido_por'  => adminAtual()['nome'] ?? '',
        'hoje'         => $dataBr($hoje),
        'ate'          => $dataBr($ate),
        'ate_iso'      => $ate,
        'total_livros' => contar($pdo, 'SELECT COUNT(*) FROM livros'),
        'abertos'      => $abertos,
        'por_livro'    => $porLivro,
        'por_leitor'   => $porLeitor,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- números do período ----------
$emprestimosPeriodo = contar($pdo,
    'SELECT COUNT(*) FROM emprestimos WHERE data_emprestimo BETWEEN :de AND :ate', $intervalo);

$devolucoesPeriodo = contar($pdo,
    'SELECT COUNT(*) FROM emprestimos WHERE data_devolucao BETWEEN :de AND :ate', $intervalo);

// comparação entre duas colunas funciona igual em MySQL e Postgres
$devolucoesNoPrazo = contar($pdo,
    'SELECT COUNT(*) FROM emprestimos
      WHERE data_devolucao BETWEEN :de AND :ate AND data_devolucao <= data_prevista', $intervalo);

$percentualNoPrazo = $devolucoesPeriodo > 0
    ? (int) round(($devolucoesNoPrazo / $devolucoesPeriodo) * 100)
    : null;

$novosLeitores = contar($pdo,
    'SELECT COUNT(*) FROM leitores WHERE data_cadastro BETWEEN :de AND :ate',
    ['de' => $deInicio, 'ate' => $ateFim]);

// estes dois são a situação de AGORA, não do período
$atrasadosAgora    = contar($pdo, "SELECT COUNT(*) FROM emprestimos WHERE status = 'atrasado'");
$renovacoesPendentes = contar($pdo, 'SELECT COUNT(*) FROM emprestimos WHERE renovacao_solicitada = TRUE');

// ---------- livros mais emprestados no período ----------
$maisEmprestados = consultar($pdo,
    'SELECT l.id, l.titulo, l.autor, l.codigo, COUNT(*) AS qtd
       FROM emprestimos e
       JOIN livros l ON l.id = e.livro_id
      WHERE e.data_emprestimo BETWEEN :de AND :ate
      GROUP BY l.id, l.titulo, l.autor, l.codigo
      ORDER BY qtd DESC, l.titulo ASC', $intervalo);
$maiorEmprestado = maiorQtd($maisEmprestados);
$topEmprestados = array_slice($maisEmprestados, 0, 10);

// ---------- empréstimos por categoria no período ----------
$porCategoria = consultar($pdo,
    'SELECT c.id, c.nome, COUNT(e.id) AS qtd
       FROM categorias c
       LEFT JOIN livros l ON l.categoria_id = c.id
       LEFT JOIN emprestimos e ON e.livro_id = l.id
                              AND e.data_emprestimo BETWEEN :de AND :ate
      GROUP BY c.id, c.nome
      ORDER BY qtd DESC, c.nome ASC', $intervalo);
$porCategoria = agruparOutras($porCategoria);
// escala só pelos gêneros reais: a linha "Outras" não tem barra
$maiorCategoria = maiorQtd(array_filter($porCategoria, function ($c) { return !isset($c['detalhe']); }));

// ---------- leitores mais ativos no período ----------
$leitoresAtivos = consultar($pdo,
    'SELECT r.id, r.nome, r.email, COUNT(*) AS qtd
       FROM emprestimos e
       JOIN leitores r ON r.id = e.leitor_id
      WHERE e.data_emprestimo BETWEEN :de AND :ate
      GROUP BY r.id, r.nome, r.email
      ORDER BY qtd DESC, r.nome ASC', $intervalo);
$topLeitores = array_slice($leitoresAtivos, 0, 10);

// ---------- atrasos em aberto (situação atual) ----------
$atrasos = consultar($pdo,
    "SELECT e.id, e.data_emprestimo, e.data_prevista, e.renovacoes,
            l.titulo AS livro_titulo, r.nome AS leitor_nome, r.email AS leitor_email
       FROM emprestimos e
       JOIN livros l   ON l.id = e.livro_id
       JOIN leitores r ON r.id = e.leitor_id
      WHERE e.status = 'atrasado'
      ORDER BY e.data_prevista ASC");

// dias de atraso calculados no PHP (não com DATEDIFF), para funcionar nos dois bancos
$hojeTs = strtotime($hoje);
foreach ($atrasos as &$atraso) {
    $atraso['dias'] = (int) round(($hojeTs - strtotime($atraso['data_prevista'])) / 86400);
}
unset($atraso);

// ---------- movimentação mês a mês ----------
$meses = movimentoPorMes($pdo, $de, $ate);

// ---------- duração média e renovações (empréstimos devolvidos no período) ----------
// diferença de datas calculada no PHP, para funcionar nos dois bancos
$devolvidos = consultar($pdo,
    "SELECT data_emprestimo, data_devolucao, renovacoes FROM emprestimos
      WHERE data_devolucao BETWEEN :de AND :ate", $intervalo);
$somaDias = 0;
$comRenovacao = 0;
foreach ($devolvidos as $linha) {
    $somaDias += max(0, (int) round((strtotime($linha["data_devolucao"]) - strtotime($linha["data_emprestimo"])) / 86400));
    if ((int) $linha["renovacoes"] > 0) {
        $comRenovacao++;
    }
}
$duracaoMedia = $devolvidos ? round($somaDias / count($devolvidos), 1) : null;
$percentualRenovados = $devolvidos ? (int) round(($comRenovacao / count($devolvidos)) * 100) : null;

// ---------- reservas feitas no período, por situação ----------
$reservasPeriodo = ["pendente" => 0, "atendida" => 0, "cancelada" => 0];
foreach (consultar($pdo,
    "SELECT status, COUNT(*) AS qtd FROM reservas
      WHERE data_reserva BETWEEN :de AND :ate GROUP BY status", $intervalo) as $linha) {
    $reservasPeriodo[$linha["status"]] = (int) $linha["qtd"];
}
$totalReservasPeriodo = array_sum($reservasPeriodo);

// ---------- empréstimos por dia da semana ----------
$DIAS_SEMANA = [1 => "Segunda", 2 => "Terça", 3 => "Quarta", 4 => "Quinta", 5 => "Sexta", 6 => "Sábado", 7 => "Domingo"];
$porDiaSemana = array_fill_keys(array_keys($DIAS_SEMANA), 0);
foreach (consultar($pdo,
    "SELECT data_emprestimo FROM emprestimos WHERE data_emprestimo BETWEEN :de AND :ate", $intervalo) as $linha) {
    $porDiaSemana[(int) date("N", strtotime($linha["data_emprestimo"]))]++;
}
$maiorDiaSemana = max(1, max($porDiaSemana));

// ---------- autores mais lidos no período ----------
$autoresLidos = array_slice(consultar($pdo,
    "SELECT l.autor, COUNT(*) AS qtd, COUNT(DISTINCT l.id) AS titulos
       FROM emprestimos e
       JOIN livros l ON l.id = e.livro_id
      WHERE e.data_emprestimo BETWEEN :de AND :ate
      GROUP BY l.autor
      ORDER BY qtd DESC, l.autor ASC", $intervalo), 0, 5);
$maiorAutor = maiorQtd($autoresLidos);

// ---------- livros parados: sem nenhum empréstimo no período ----------
$livrosParados = consultar($pdo,
    "SELECT l.id, l.titulo, l.autor, l.exemplares_total, c.nome AS categoria
       FROM livros l
       JOIN categorias c ON c.id = l.categoria_id
      WHERE NOT EXISTS (SELECT 1 FROM emprestimos e
                         WHERE e.livro_id = l.id AND e.data_emprestimo BETWEEN :de AND :ate)
      ORDER BY l.titulo ASC", $intervalo);
$totalLivros = contar($pdo, "SELECT COUNT(*) FROM livros");

/** Monta uma URL desta página preservando o período escolhido. */
function urlRelatorio(array $extra = []): string
{
    $base = ['periodo' => $GLOBALS['periodo'], 'de' => $GLOBALS['de'], 'ate' => $GLOBALS['ate']];
    return 'relatorios.php?' . http_build_query(array_merge($base, $extra));
}

$tituloAba = 'Relatórios';
$paginaAtual = 'relatorios';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Relatórios</div>
        <h2>Relatórios da biblioteca</h2>
        <p class="text-muted" style="margin-top:6px;font-size:13.5px;">
          Período analisado: <strong><?= date('d/m/Y', strtotime($de)) ?></strong>
          até <strong><?= date('d/m/Y', strtotime($ate)) ?></strong>
        </p>
      </div>
      <button type="button" class="btn primary small" data-pdf="completo">Baixar relatório em PDF</button>
    </div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px;">
      <?php foreach ($PERIODOS as $slug => $label): if ($slug === 'personalizado') continue; ?>
        <a href="relatorios.php?periodo=<?= e($slug) ?>"
           class="btn <?= $periodo === $slug ? 'primary' : 'ghost' ?> small"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <form method="get" action="relatorios.php" class="periodo-form"
          style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:22px;">
      <input type="hidden" name="periodo" value="personalizado">
      <div class="field" style="margin-bottom:0;">
        <label for="de">De</label>
        <input type="date" id="de" name="de" value="<?= e($de) ?>" required>
      </div>
      <div class="field" style="margin-bottom:0;">
        <label for="ate">Até</label>
        <input type="date" id="ate" name="ate" value="<?= e($ate) ?>" required>
      </div>
      <button type="submit" class="btn <?= $periodo === 'personalizado' ? 'primary' : 'ghost' ?> small">Aplicar período</button>
    </form>

    <div class="panel pdf-painel" data-pdf-url="<?= e(urlRelatorio(['dados_pdf' => '1'])) ?>">
      <h3>Baixar relatórios em PDF</h3>
      <p class="sub">
        Empréstimos em aberto e atrasos mostram a situação de hoje (<?= date('d/m/Y') ?>).
        Os totais por livro e por leitor contam tudo o que foi emprestado até
        <strong><?= date('d/m/Y', strtotime($ate)) ?></strong> (a data "Até" do período).
      </p>
      <div class="pdf-botoes">
        <button type="button" class="btn ghost small" data-pdf="abertos">Livros emprestados</button>
        <button type="button" class="btn ghost small" data-pdf="atrasados">Livros atrasados</button>
        <button type="button" class="btn ghost small" data-pdf="livros">Empréstimos por livro</button>
        <button type="button" class="btn ghost small" data-pdf="leitores">Empréstimos por leitor</button>
        <button type="button" class="btn primary small" data-pdf="completo">Relatório completo</button>
      </div>
      <p class="pdf-aviso" aria-live="polite" hidden></p>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="rotulo">Empréstimos no período</div>
        <div class="valor"><?= $emprestimosPeriodo ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Devoluções no período</div>
        <div class="valor"><?= $devolucoesPeriodo ?></div>
      </div>
      <div class="stat-card <?= $percentualNoPrazo !== null && $percentualNoPrazo >= 80 ? 'ok' : ($percentualNoPrazo !== null ? 'warn' : '') ?>">
        <div class="rotulo">Devolvidos no prazo</div>
        <div class="valor">
          <?php if ($percentualNoPrazo === null): ?>
            —
          <?php else: ?>
            <?= $percentualNoPrazo ?><span style="font-size:16px;color:var(--texto-secundario);">%</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="stat-card <?= $atrasadosAgora > 0 ? 'alert' : 'ok' ?>">
        <div class="rotulo">Atrasados agora</div>
        <div class="valor"><?= $atrasadosAgora ?></div>
      </div>
      <div class="stat-card <?= $renovacoesPendentes > 0 ? 'warn' : '' ?>">
        <div class="rotulo">Renovações aguardando</div>
        <div class="valor"><?= $renovacoesPendentes ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Novos leitores no período</div>
        <div class="valor"><?= $novosLeitores ?></div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Duração média do empréstimo</div>
        <div class="valor">
          <?php if ($duracaoMedia === null): ?>
            —
          <?php else: ?>
            <?= number_format($duracaoMedia, 1, ',', '') ?><span style="font-size:16px;color:var(--texto-secundario);"> dias</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="rotulo">Devolvidos com renovação</div>
        <div class="valor">
          <?= $percentualRenovados === null ? '—' : $percentualRenovados . '<span style="font-size:16px;color:var(--texto-secundario);">%</span>' ?>
        </div>
      </div>
    </div>

    <!-- ===== Circulação ===== -->
    <div class="secao-titulo">Circulação</div>
    <div class="dash-row">
      <div class="panel span-8">
        <h3>Movimentação mês a mês</h3>
        <?php if ($emprestimosPeriodo === 0 && $devolucoesPeriodo === 0): ?>
          <p class="vazio">Nenhuma movimentação registrada neste período.</p>
        <?php else: ?>
          <?= graficoMovimento($meses) ?>
        <?php endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Empréstimos por dia da semana</h3>
        <p class="sub">Ajuda a planejar os horários de maior movimento no balcão.</p>
        <?php if ($emprestimosPeriodo === 0): ?>
          <p class="vazio">Nenhum empréstimo registrado neste período.</p>
        <?php else: foreach ($DIAS_SEMANA as $num => $nomeDia): ?>
          <div class="bar-row">
            <div class="rotulo" style="width:70px;"><?= $nomeDia ?></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($porDiaSemana[$num] / $maiorDiaSemana) * 100) ?>%;"></div></div>
            <div class="qtd"><?= $porDiaSemana[$num] ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ===== O que foi lido ===== -->
    <div class="secao-titulo">O que foi lido</div>
    <div class="dash-row">
      <div class="panel span-4">
        <h3>Livros mais emprestados</h3>
        <p class="sub">Top 5 do período<?= count($maisEmprestados) > 5 ? ' (de ' . count($maisEmprestados) . ' títulos emprestados)' : '' ?>.</p>
        <?php if (!$topEmprestados): ?>
          <p class="vazio">Nenhum empréstimo registrado neste período.</p>
        <?php else: foreach (array_slice($topEmprestados, 0, 5) as $i => $livro): ?>
          <div class="attention-item">
            <span title="<?= e($livro['titulo']) ?> — <?= e($livro['autor']) ?>">
              <strong class="mono" style="color:var(--roxo);">#<?= $i + 1 ?></strong> <?= e($livro['titulo']) ?><br>
              <span class="text-muted" style="font-size:12px;"><?= e($livro['autor']) ?></span>
            </span>
            <span class="mono text-muted"><?= (int) $livro['qtd'] ?>x</span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Autores mais lidos</h3>
        <p class="sub">Empréstimos no período, somando todos os títulos do autor.</p>
        <?php if (!$autoresLidos): ?>
          <p class="vazio">Nenhum empréstimo registrado neste período.</p>
        <?php else: foreach ($autoresLidos as $autor): ?>
          <div class="bar-row">
            <div class="rotulo" style="width:120px;" title="<?= e($autor['autor']) ?> · <?= (int) $autor['titulos'] ?> título(s)"><?= e($autor['autor']) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($autor['qtd'] / $maiorAutor) * 100) ?>%;"></div></div>
            <div class="qtd"><?= (int) $autor['qtd'] ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Empréstimos por categoria</h3>
        <p class="sub">Quais áreas do acervo mais circularam no período.</p>
        <?php foreach ($porCategoria as $cat): ?>
          <div class="bar-row">
            <div class="rotulo" style="width:120px;"<?= isset($cat['detalhe']) ? ' title="' . e($cat['detalhe']) . '"' : '' ?>><?= e($cat['nome']) ?></div>
            <?php if (isset($cat['detalhe'])): ?>
              <div class="text-muted" style="flex:1;font-size:12px;white-space:nowrap;">demais gêneros</div>
            <?php else: ?>
              <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($cat['qtd'] / $maiorCategoria) * 100) ?>%;"></div></div>
            <?php endif; ?>
            <div class="qtd"><?= (int) $cat['qtd'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ===== Leitores, reservas e acervo parado ===== -->
    <div class="secao-titulo">Leitores e acervo</div>
    <div class="dash-row">
      <div class="panel span-4">
        <h3>Leitores mais ativos</h3>
        <?php if (!$topLeitores): ?>
          <p class="vazio">Nenhum leitor pegou livros emprestados neste período.</p>
        <?php else: foreach (array_slice($topLeitores, 0, 5) as $i => $leitor): ?>
          <div class="attention-item">
            <span>
              <strong class="mono" style="color:var(--roxo);">#<?= $i + 1 ?></strong> <?= e($leitor['nome']) ?><br>
              <span class="text-muted" style="font-size:12px;"><?= e($leitor['email']) ?></span>
            </span>
            <span class="mono text-muted"><?= (int) $leitor['qtd'] ?>x</span>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Reservas no período</h3>
        <p class="sub">Pedidos feitos entre as datas escolhidas, pela situação atual.</p>
        <?php if ($totalReservasPeriodo === 0): ?>
          <p class="vazio">Nenhuma reserva feita neste período.</p>
        <?php else: ?>
          <div class="meter-valor"><?= $totalReservasPeriodo ?> <small>reserva<?= $totalReservasPeriodo === 1 ? '' : 's' ?></small></div>
          <div class="stack" style="margin-top:12px;" role="img" aria-label="<?= e("Atendidas: {$reservasPeriodo['atendida']}, pendentes: {$reservasPeriodo['pendente']}, canceladas: {$reservasPeriodo['cancelada']}") ?>">
            <?php foreach (['atendida' => 'var(--ok)', 'pendente' => 'var(--aviso)', 'cancelada' => 'var(--texto-secundario)'] as $st => $cor): if ($reservasPeriodo[$st] > 0): ?>
              <div style="width: <?= round(($reservasPeriodo[$st] / $totalReservasPeriodo) * 100, 1) ?>%; background: <?= $cor ?>;"></div>
            <?php endif; endforeach; ?>
          </div>
          <?php foreach ([['Atendidas', 'atendida', 'var(--ok)'], ['Pendentes', 'pendente', 'var(--aviso)'], ['Canceladas', 'cancelada', 'var(--texto-secundario)']] as [$nome, $st, $cor]): ?>
            <div class="attention-item">
              <span style="display:inline-flex;align-items:center;gap:8px;"><i style="width:10px;height:10px;border-radius:3px;background:<?= $cor ?>;display:inline-block;"></i><?= $nome ?></span>
              <strong class="mono"><?= $reservasPeriodo[$st] ?></strong>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="panel span-4">
        <h3>Livros sem empréstimo</h3>
        <p class="sub"><?= count($livrosParados) ?> de <?= $totalLivros ?> títulos não saíram no período — bons candidatos para divulgação.</p>
        <?php if (!$livrosParados): ?>
          <p class="vazio">Todos os títulos foram emprestados ao menos uma vez neste período.</p>
        <?php else: foreach (array_slice($livrosParados, 0, 5) as $livro): ?>
          <div class="attention-item">
            <span>
              <a href="livros.php?ver=<?= (int) $livro['id'] ?>"><strong><?= e($livro['titulo']) ?></strong></a><br>
              <span class="text-muted" style="font-size:12px;"><?= e($livro['autor']) ?> · <?= e($livro['categoria']) ?></span>
            </span>
            <span class="mono text-muted" style="font-size:12px;"><?= (int) $livro['exemplares_total'] ?> ex.</span>
          </div>
        <?php endforeach; if (count($livrosParados) > 5): ?>
          <p class="text-muted" style="font-size:12px;margin:12px 0 0;">E mais <?= count($livrosParados) - 5 ?> título(s).</p>
        <?php endif; endif; ?>
      </div>
    </div>

    <div class="view-head" style="margin-bottom:12px;">
      <div>
        <h3 style="font-size:18px;">Atrasos em aberto</h3>
        <p class="text-muted" style="margin-top:4px;font-size:12.5px;">
          Situação de hoje (<?= date('d/m/Y') ?>), independente do período escolhido acima.
        </p>
      </div>
    </div>

    <?php if (!$atrasos): ?>
      <div class="panel">
        <p class="text-muted" style="font-size:13px;margin:0;">Nenhum empréstimo em atraso no momento.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap tabela-cartoes sem-acoes" style="margin-bottom:24px;">
        <table>
          <thead>
            <tr>
              <th>Livro</th>
              <th>Leitor</th>
              <th>Emprestado em</th>
              <th>Prazo</th>
              <th>Atraso</th>
              <th>Renovações</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($atrasos as $atraso): ?>
              <tr>
                <td><strong><?= e($atraso['livro_titulo']) ?></strong></td>
                <td>
                  <?= e($atraso['leitor_nome']) ?><br>
                  <span class="text-muted" style="font-size:12px;"><?= e($atraso['leitor_email']) ?></span>
                </td>
                <td class="mono"><?= date('d/m/Y', strtotime($atraso['data_emprestimo'])) ?></td>
                <td class="mono"><?= date('d/m/Y', strtotime($atraso['data_prevista'])) ?></td>
                <td><span class="badge alert"><?= (int) $atraso['dias'] ?> dia<?= (int) $atraso['dias'] === 1 ? '' : 's' ?></span></td>
                <td class="mono"><?= (int) $atraso['renovacoes'] ?>/2</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-bottom:24px;">
        <a href="emprestimos.php?status=atrasado" class="btn ghost small">Ver na tela de empréstimos</a>
      </div>
    <?php endif; ?>

<script src="assets/js/vendor/jspdf.umd.min.js?v=2.5.1"></script>
<script src="assets/js/vendor/jspdf.plugin.autotable.min.js?v=3.8.2"></script>
<script src="assets/js/relatorios-pdf.js?v=<?= VERSAO_ASSETS ?>"></script>
<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
