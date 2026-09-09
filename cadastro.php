<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Se já estiver logado, manda direto para o sistema
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$erro = pegarFlash();
$valores = $_SESSION['cadastro_valores'] ?? [];
unset($_SESSION['cadastro_valores']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criar conta — BiblioTech</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css">
</head>
<body class="login-page">
  <div class="login-card" style="max-width:420px;">
    <div class="eyebrow">Estante Digital</div>
    <h1>Criar conta de aluno</h1>

    <?php if ($erro): ?>
      <div class="alert-box"><?= e($erro['mensagem']) ?></div>
    <?php endif; ?>

    <form method="post" action="cadastro_salvar.php" novalidate>
      <div class="field">
        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" required autofocus value="<?= e($valores['nome'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required value="<?= e($valores['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="telefone">Telefone (opcional)</label>
        <input type="text" id="telefone" name="telefone" value="<?= e($valores['telefone'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required minlength="6">
      </div>
      <div class="field">
        <label for="confirmar_senha">Confirmar senha</label>
        <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="6">
      </div>
      <button type="submit" class="btn brass full">Criar minha conta</button>
    </form>

    <div class="hint">
      Já tem conta? <a href="login.php">Fazer login</a>
    </div>
  </div>
</body>
</html>
