<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Se já estiver logado, manda direto para o sistema
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$erro = '';
$sucesso = pegarFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';

    if ($email === '' || $senha === '') {
        $erro = 'Preencha e-mail e senha.';
    } else {
        $resultado = autenticar($email, $senha);
        if ($resultado['ok']) {
            header('Location: index.php');
            exit;
        }
        $erro = $resultado['erro'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — BiblioTech</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css">
</head>
<body class="login-page">
  <div class="login-card">
    <div class="eyebrow">Estante Digital</div>
    <h1>BiblioTech</h1>

    <?php if ($sucesso): ?>
      <div class="alert-box" style="background:rgba(63,107,79,0.1);border-color:rgba(63,107,79,0.3);color:#3f6b4f;"><?= htmlspecialchars($sucesso['mensagem']) ?></div>
    <?php endif; ?>
    <?php if ($erro): ?>
      <div class="alert-box"><?= htmlspecialchars($erro) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <div class="field">
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autofocus value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required>
      </div>
      <button type="submit" class="btn brass full">Entrar</button>
    </form>

    <div class="hint">
      Login único para alunos e administradores — o acesso é liberado conforme o seu cadastro.<br>
      Ainda não tem conta? <a href="cadastro.php">Cadastre-se</a>
    </div>
  </div>
</body>
</html>
