<?php
/**
 * Cabeçalho comum a todas as páginas internas do sistema.
 *
 * A página que faz o "include" deste arquivo deve, ANTES de incluí-lo,
 * ter chamado requireLogin() e definido as variáveis:
 *   $tituloAba    -> título mostrado na aba do navegador
 *   $paginaAtual  -> slug da página atual, para destacar no menu
 *                    ('painel' | 'livros' | 'leitores' | 'emprestimos' | 'reservas')
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

function linkAtivo(string $slug, string $paginaAtual): string
{
    return $slug === $paginaAtual ? ' class="active"' : '';
}

function navContador(int $qtd): string
{
    return $qtd > 0 ? ' <span class="nav-count">' . $qtd . '</span>' : '';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($tituloAba ?? 'Acervo') ?> — Acervo</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="eyebrow">Sistema · Biblioteca Municipal</div>
      <h1>Acervo</h1>
    </div>
    <nav>
      <a href="index.php"<?= linkAtivo('painel', $paginaAtual) ?>>Painel</a>
      <a href="livros.php"<?= linkAtivo('livros', $paginaAtual) ?>>Acervo (livros)</a>
      <?php if (isAdmin()): ?>
        <a href="leitores.php"<?= linkAtivo('leitores', $paginaAtual) ?>>Leitores</a>
        <a href="emprestimos.php"<?= linkAtivo('emprestimos', $paginaAtual) ?>>Empréstimos<?= navContador($navAtrasados) ?></a>
        <a href="reservas.php"<?= linkAtivo('reservas', $paginaAtual) ?>>Reservas<?= navContador($navReservasPendentes) ?></a>
      <?php else: ?>
        <a href="emprestimos.php"<?= linkAtivo('emprestimos', $paginaAtual) ?>>Meus empréstimos<?= navContador($navAtrasados) ?></a>
        <a href="reservas.php"<?= linkAtivo('reservas', $paginaAtual) ?>>Minhas reservas<?= navContador($navReservasPendentes) ?></a>
      <?php endif; ?>
    </nav>
    <div class="who">
      Logado como <strong><?= e($admin['nome']) ?></strong> <span style="opacity:0.7;">(<?= isAdmin() ? 'Administrador' : 'Aluno' ?>)</span>
      <form method="post" action="logout.php">
        <button type="submit">Sair do sistema</button>
      </form>
    </div>
  </aside>

  <main>
    <?php if ($flash): ?>
      <div class="alert-box" style="<?= $flash['tipo'] === 'ok' ? 'background:rgba(63,107,79,0.1);border-color:rgba(63,107,79,0.3);color:#3f6b4f;' : '' ?>">
        <?= e($flash['mensagem']) ?>
      </div>
    <?php endif; ?>
