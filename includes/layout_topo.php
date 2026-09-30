<?php
/**
 * Cabeçalho comum a todas as páginas internas do sistema.
 *
 * A página que faz o "include" deste arquivo deve, ANTES de incluí-lo,
 * ter chamado requireLogin() e definido as variáveis:
 *   $tituloAba    -> título mostrado na aba do navegador
 *   $paginaAtual  -> slug da página atual, para destacar no menu
 *                    ('painel' | 'livros' | 'leitores' | 'emprestimos' | 'reservas' | 'relatorios' | 'configuracoes')
 */

$admin = adminAtual();
$flash = pegarFlash();

$pdoNav = getConexao();
if (isAdmin()) {
    $navAtrasados = (int) $pdoNav->query("SELECT COUNT(*) FROM emprestimos WHERE status = 'atrasado'")->fetchColumn();
    $navReservasPendentes = (int) $pdoNav->query("SELECT COUNT(*) FROM reservas WHERE status = 'pendente'")->fetchColumn();
} else {
    $stmt = $pdoNav->prepare("SELECT COUNT(*) FROM emprestimos WHERE status = 'atrasado' AND leitor_id = :id");
    $stmt->execute(['id' => $admin['id']]);
    $navAtrasados = (int) $stmt->fetchColumn();

    $stmt = $pdoNav->prepare("SELECT COUNT(*) FROM reservas WHERE status = 'pendente' AND leitor_id = :id");
    $stmt->execute(['id' => $admin['id']]);
    $navReservasPendentes = (int) $stmt->fetchColumn();
}
$navTotalAvisos = $navAtrasados + $navReservasPendentes;

function linkAtivo(string $slug, string $paginaAtual): string
{
    return $slug === $paginaAtual ? ' class="active"' : '';
}

function navContador(int $qtd): string
{
    return $qtd > 0 ? ' <span class="nav-count">' . $qtd . '</span>' : '';
}

/** Ícones simples (outline, 18x18) usados no menu lateral. */
function navIcone(string $slug): string
{
    $icones = [
        'painel'        => '<path d="M3 10.5 10 4l7 6.5M5 9v7h10V9" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'livros'        => '<path d="M3 4.5c2-1 4.6-1 6.5.3v9.7c-1.9-1.3-4.5-1.3-6.5-.3V4.5ZM16.5 4.5c-2-1-4.6-1-6.5.3v9.7c1.9-1.3 4.5-1.3 6.5-.3V4.5Z" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linejoin="round"/>',
        'leitores'      => '<circle cx="10" cy="6.5" r="2.6" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M4 16c.6-3 3-4.5 6-4.5s5.4 1.5 6 4.5" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round"/>',
        'emprestimos'   => '<rect x="3.5" y="5" width="13" height="10.5" rx="1.6" stroke="currentColor" stroke-width="1.5" fill="none"/><path d="M3.5 8.2h13M7 5V3.6M13 5V3.6" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round"/>',
        'reservas'      => '<circle cx="10" cy="10" r="6.5" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M10 6.5V10l2.6 1.6" stroke="currentColor" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
        'relatorios'    => '<path d="M4 16V8.5M9 16V4M14 16v-5" stroke="currentColor" stroke-width="1.7" fill="none" stroke-linecap="round"/>',
        'configuracoes' => '<circle cx="10" cy="10" r="2.6" stroke="currentColor" stroke-width="1.6" fill="none"/><path d="M10 3.5v2M10 14.5v2M3.5 10h2M14.5 10h2M5.4 5.4l1.4 1.4M13.2 13.2l1.4 1.4M5.4 14.6l1.4-1.4M13.2 6.8l1.4-1.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
    ];
    return '<span class="icon"><svg viewBox="0 0 20 20">' . ($icones[$slug] ?? '') . '</svg></span>';
}
?>
<!DOCTYPE html>
<html lang="pt-BR"<?= atributoTema() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($tituloAba ?? 'Painel') ?> — Bibliotech</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css?v=<?= VERSAO_ASSETS ?>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <?= bibliotechLogo(34) ?>
      <div class="brand-text">
        <div class="brand-name">Bibliotech</div>
        <div class="brand-tag">Estante Digital</div>
      </div>
    </div>
    <nav>
      <a href="index.php"<?= linkAtivo('painel', $paginaAtual) ?>><?= navIcone('painel') ?> Início</a>
      <a href="livros.php"<?= linkAtivo('livros', $paginaAtual) ?>><?= navIcone('livros') ?> Livros</a>
      <?php if (isAdmin()): ?>
        <a href="leitores.php"<?= linkAtivo('leitores', $paginaAtual) ?>><?= navIcone('leitores') ?> Usuários</a>
      <?php endif; ?>
      <a href="emprestimos.php"<?= linkAtivo('emprestimos', $paginaAtual) ?>><?= navIcone('emprestimos') ?> <?= isAdmin() ? 'Empréstimos' : 'Meus empréstimos' ?><?= navContador($navAtrasados) ?></a>
      <a href="reservas.php"<?= linkAtivo('reservas', $paginaAtual) ?>><?= navIcone('reservas') ?> <?= isAdmin() ? 'Reservas' : 'Minhas reservas' ?><?= navContador($navReservasPendentes) ?></a>
      <?php if (isAdmin()): ?>
        <a href="relatorios.php"<?= linkAtivo('relatorios', $paginaAtual) ?>><?= navIcone('relatorios') ?> Relatórios</a>
      <?php endif; ?>
      <a href="configuracoes.php"<?= linkAtivo('configuracoes', $paginaAtual) ?>><?= navIcone('configuracoes') ?> Configurações</a>
    </nav>
  </aside>

  <div class="main-wrap">
    <header class="topbar">
      <button type="button" class="hamburger" aria-label="Abrir menu"><span></span></button>

      <div class="topbar-search">
        <form method="get" action="livros.php">
          <svg viewBox="0 0 20 20" fill="none"><circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.6"/><path d="M14 14l3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
          <input type="search" name="busca" placeholder="Buscar livros por título ou autor…">
        </form>
      </div>

      <div class="topbar-right">
        <div class="dropdown-wrap">
          <button type="button" class="icon-btn dropdown-trigger" aria-label="Notificações">
            <svg viewBox="0 0 20 20" fill="none"><path d="M5 8a5 5 0 0 1 10 0c0 2.2.6 3.6 1.3 4.4.4.4.1 1.1-.5 1.1H4.2c-.6 0-.9-.7-.5-1.1C4.4 11.6 5 10.2 5 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8 16a2 2 0 0 0 4 0" stroke="currentColor" stroke-width="1.5"/></svg>
            <?php if ($navTotalAvisos > 0): ?><span class="dot"></span><?php endif; ?>
          </button>
          <div class="dropdown">
            <div class="dropdown-title">Notificações</div>
            <?php if ($navTotalAvisos === 0): ?>
              <div class="dropdown-empty">Nenhuma notificação por aqui.</div>
            <?php else: ?>
              <?php if ($navAtrasados > 0): ?>
                <a class="dropdown-item" href="emprestimos.php">
                  <?= isAdmin() ? "$navAtrasados empréstimo(s) atrasado(s)" : "Você tem $navAtrasados empréstimo(s) atrasado(s)" ?>
                </a>
              <?php endif; ?>
              <?php if ($navReservasPendentes > 0): ?>
                <a class="dropdown-item" href="reservas.php">
                  <?= isAdmin() ? "$navReservasPendentes reserva(s) pendente(s) na fila" : "Você tem $navReservasPendentes reserva(s) pendente(s)" ?>
                </a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="dropdown-wrap">
          <button type="button" class="user-menu-btn dropdown-trigger">
            <span class="avatar-init"><?= e(iniciaisNome($admin['nome'])) ?></span>
            <span class="user-info">
              <span class="u-nome"><?= e($admin['nome']) ?></span><br>
              <span class="u-tipo"><?= isAdmin() ? 'Administrador' : 'Aluno' ?></span>
            </span>
          </button>
          <div class="dropdown">
            <a class="dropdown-item" href="configuracoes.php">Configurações</a>
            <hr>
            <form method="post" action="logout.php">
              <button type="submit">Sair do sistema</button>
            </form>
          </div>
        </div>
      </div>
    </header>

    <main>
      <?php if ($flash): ?>
        <div class="alert-box <?= $flash['tipo'] === 'ok' ? 'ok' : '' ?>">
          <?= e($flash['mensagem']) ?>
          <?php if (!empty($flash['acao'])): ?>
            <a class="alert-acao" href="<?= e($flash['acao']['url']) ?>"><?= e($flash['acao']['texto']) ?></a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
