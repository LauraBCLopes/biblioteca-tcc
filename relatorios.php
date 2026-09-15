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

// ---------- exportação CSV ----------
// Fica antes das consultas do relatório: a exportação só precisa do período,
// e sai daqui direto (antes de qualquer HTML, por causa dos headers).
if (($_GET['exportar'] ?? '') === 'csv') {
    $linhas = consultar($pdo,
        'SELECT e.data_emprestimo, e.data_prevista, e.data_devolucao, e.status, e.renovacoes,
                l.codigo AS livro_codigo, l.titulo AS livro_titulo,
                r.nome AS leitor_nome, r.email AS leitor_email
           FROM emprestimos e
           JOIN livros l   ON l.id = e.livro_id
           JOIN leitores r ON r.id = e.leitor_id
          WHERE e.data_emprestimo BETWEEN :de AND :ate
          ORDER BY e.data_emprestimo ASC', $intervalo);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio-' . $de . '-a-' . $ate . '.csv"');

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");   // BOM: faz o Excel abrir os acentos corretamente
    fputcsv($saida, ['Empréstimo', 'Prazo', 'Devolução', 'Situação', 'Renovações',
                     'Código', 'Livro', 'Leitor', 'E-mail'], ';');

    foreach ($linhas as $linha) {
        fputcsv($saida, [
            date('d/m/Y', strtotime($linha['data_emprestimo'])),
            date('d/m/Y', strtotime($linha['data_prevista'])),
            $linha['data_devolucao'] ? date('d/m/Y', strtotime($linha['data_devolucao'])) : '',
            $linha['status'],
            (int) $linha['renovacoes'],
            $linha['livro_codigo'],
            $linha['livro_titulo'],
            $linha['leitor_nome'],
            $linha['leitor_email'],
        ], ';');
    }
    fclose($saida);
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
$renovacoesPendentes = contar($pdo, 'SELECT COUNT(*) FROM emprestimos WHERE renovacao_solicitada = 1');

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
$maiorCategoria = maiorQtd($porCategoria);

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
// Agrupamento feito no PHP: MySQL usa DATE_FORMAT e Postgres usa TO_CHAR,
// então buscar as datas e contar aqui mantém uma única versão da consulta.
$MESES_ABREV = ['01'=>'jan','02'=>'fev','03'=>'mar','04'=>'abr','05'=>'mai','06'=>'jun',
                '07'=>'jul','08'=>'ago','09'=>'set','10'=>'out','11'=>'nov','12'=>'dez'];

$meses = [];
$cursor = new DateTime(substr($de, 0, 7) . '-01');
$ultimoMes = new DateTime(substr($ate, 0, 7) . '-01');
while ($cursor <= $ultimoMes && count($meses) < 24) {   // teto de 24 meses: gráfico legível
    $meses[$cursor->format('Y-m')] = ['emprestimos' => 0, 'devolucoes' => 0];
    $cursor->modify('+1 month');
}

// placeholders distintos para cada BETWEEN: com EMULATE_PREPARES desligado,
// o mesmo nome não pode aparecer duas vezes na consulta
$movimento = consultar($pdo,
    'SELECT data_emprestimo, data_devolucao FROM emprestimos
      WHERE data_emprestimo BETWEEN :de_emp AND :ate_emp
         OR data_devolucao  BETWEEN :de_dev AND :ate_dev',
    ['de_emp' => $de, 'ate_emp' => $ate, 'de_dev' => $de, 'ate_dev' => $ate]);

foreach ($movimento as $linha) {
    $mesEmp = substr((string) $linha['data_emprestimo'], 0, 7);
    if ($linha['data_emprestimo'] >= $de && $linha['data_emprestimo'] <= $ate && isset($meses[$mesEmp])) {
        $meses[$mesEmp]['emprestimos']++;
    }
    $mesDev = substr((string) $linha['data_devolucao'], 0, 7);
    if ($linha['data_devolucao'] && $linha['data_devolucao'] >= $de && $linha['data_devolucao'] <= $ate && isset($meses[$mesDev])) {
        $meses[$mesDev]['devolucoes']++;
    }
}

$maiorMes = 1;
foreach ($meses as $dados) {
    $maiorMes = max($maiorMes, $dados['emprestimos'], $dados['devolucoes']);
}

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
      <a class="btn ghost small" href="<?= e(urlRelatorio(['exportar' => 'csv'])) ?>">Exportar CSV</a>
    </div>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px;">
      <?php foreach ($PERIODOS as $slug => $label): if ($slug === 'personalizado') continue; ?>
        <a href="relatorios.php?periodo=<?= e($slug) ?>"
           class="btn <?= $periodo === $slug ? 'primary' : 'ghost' ?> small"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>

    <form method="get" action="relatorios.php"
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
    </div>

    <div class="panel">
      <h3 style="margin-bottom:16px;">Movimentação mês a mês</h3>
      <?php if ($emprestimosPeriodo === 0 && $devolucoesPeriodo === 0): ?>
        <p class="text-muted" style="font-size:13px;">Nenhuma movimentação registrada neste período.</p>
      <?php else: ?>
        <?php foreach ($meses as $chave => $dados): ?>
          <?php list($ano, $mes) = explode('-', $chave); ?>
          <div class="bar-row">
            <div class="rotulo"><?= $MESES_ABREV[$mes] ?>/<?= $ano ?> · empréstimos</div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($dados['emprestimos'] / $maiorMes) * 100) ?>%;"></div></div>
            <div class="qtd"><?= $dados['emprestimos'] ?></div>
          </div>
          <div class="bar-row" style="margin-bottom:18px;">
            <div class="rotulo text-muted"><?= $MESES_ABREV[$mes] ?>/<?= $ano ?> · devoluções</div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($dados['devolucoes'] / $maiorMes) * 100) ?>%; background: var(--ok);"></div></div>
            <div class="qtd"><?= $dados['devolucoes'] ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="dash-grid">
      <div class="panel">
        <h3 style="margin-bottom:6px;">Livros mais emprestados</h3>
        <p class="text-muted" style="font-size:12.5px;margin:0 0 16px;">
          Top 10 do período<?= count($maisEmprestados) > 10 ? ' (de ' . count($maisEmprestados) . ' títulos emprestados)' : '' ?>.
        </p>
        <?php if (!$topEmprestados): ?>
          <p class="text-muted" style="font-size:13px;">Nenhum empréstimo registrado neste período.</p>
        <?php else: foreach ($topEmprestados as $i => $livro): ?>
          <div class="bar-row">
            <div class="rotulo" title="<?= e($livro['titulo']) ?> — <?= e($livro['autor']) ?>">
              <strong class="mono" style="color:var(--roxo);">#<?= $i + 1 ?></strong> <?= e($livro['titulo']) ?>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($livro['qtd'] / $maiorEmprestado) * 100) ?>%;"></div></div>
            <div class="qtd"><?= (int) $livro['qtd'] ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="panel">
        <h3 style="margin-bottom:16px;">Empréstimos por categoria</h3>
        <?php foreach ($porCategoria as $cat): ?>
          <div class="bar-row">
            <div class="rotulo"><?= e($cat['nome']) ?></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= (int) round(($cat['qtd'] / $maiorCategoria) * 100) ?>%;"></div></div>
            <div class="qtd"><?= (int) $cat['qtd'] ?></div>
          </div>
        <?php endforeach; ?>

        <h3 style="margin:22px 0 16px;">Leitores mais ativos</h3>
        <?php if (!$topLeitores): ?>
          <p class="text-muted" style="font-size:13px;">Nenhum leitor pegou livros emprestados neste período.</p>
        <?php else: foreach ($topLeitores as $i => $leitor): ?>
          <div class="attention-item">
            <span>
              <strong class="mono" style="color:var(--roxo);">#<?= $i + 1 ?></strong> <?= e($leitor['nome']) ?><br>
              <span class="text-muted" style="font-size:12px;"><?= e($leitor['email']) ?></span>
            </span>
            <span class="mono text-muted"><?= (int) $leitor['qtd'] ?>x</span>
          </div>
        <?php endforeach; endif; ?>
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
        <p class="text-muted" style="font-size:13px;margin:0;">Nenhum empréstimo em atraso no momento. 🎉</p>
      </div>
    <?php else: ?>
      <div class="table-wrap" style="margin-bottom:24px;">
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

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
