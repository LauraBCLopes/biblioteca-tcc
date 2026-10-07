<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR"<?= atributoTema() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Esqueci minha senha — Bibliotech</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css?v=<?= VERSAO_ASSETS ?>">
</head>
<body>
  <div class="auth-page">
    <div class="auth-nuvens" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span><span></span><span></span></div>
    <div class="auth-center">
      <div class="auth-brand">
        <div class="brand-name">Bibliotech</div>
        <div class="brand-tag">Estante Digital</div>
      </div>

      <div class="login-card" style="max-width:440px;">
        <h1>Esqueceu sua senha?</h1>

        <div class="panel" style="box-shadow:none;border:1px solid var(--linha);padding:16px 18px;margin-bottom:16px;">
          <h4 style="margin-bottom:8px;font-size:14px;">Sou aluno</h4>
          <p class="text-muted" style="font-size:13px;margin:0;">
            Peça a um administrador da biblioteca para redefinir sua senha
            (em <strong>Usuários → Redefinir senha</strong>). Depois, volte para
            <a href="cadastro.php">Criar conta</a> e cadastre uma senha nova com o
            mesmo usuário de sempre — seus dados e histórico continuam os mesmos.
          </p>
        </div>

        <div class="panel" style="box-shadow:none;border:1px solid var(--linha);padding:16px 18px;margin-bottom:0;">
          <h4 style="margin-bottom:8px;font-size:14px;">Sou administrador</h4>
          <p class="text-muted" style="font-size:13px;margin:0;">
            Por segurança, a senha de administrador só pode ser redefinida por
            quem tem acesso direto ao banco de dados do sistema. Contate o
            responsável técnico da biblioteca.
          </p>
        </div>

        <div class="hint">
          <a href="login.php">Voltar para o login</a>
        </div>
      </div>
    </div>

    <div class="auth-frase">
      <h2>Conhecimento sempre ao seu alcance.</h2>
      <span class="traco" aria-hidden="true"></span>
    </div>
  </div>
</body>
</html>
