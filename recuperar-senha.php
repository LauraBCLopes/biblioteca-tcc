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
<link rel="stylesheet" href="assets/css/estilo.css">
</head>
<body>
  <div class="auth-page">
    <div class="auth-side">
      <div class="brand-row">
        <?= bibliotechLogo(38) ?>
        <div>
          <div class="brand-name">Bibliotech</div>
          <div class="brand-tag">Estante Digital</div>
        </div>
      </div>
      <div class="illustration">
        <svg width="220" height="140" viewBox="0 0 220 140" fill="none">
          <rect x="20" y="80" width="180" height="14" rx="4" fill="rgba(255,255,255,0.25)"/>
          <rect x="35" y="35" width="34" height="52" rx="3" fill="rgba(255,255,255,0.85)"/>
          <rect x="75" y="20" width="34" height="67" rx="3" fill="rgba(255,255,255,0.6)"/>
          <rect x="115" y="45" width="34" height="42" rx="3" fill="rgba(255,255,255,0.85)"/>
          <rect x="155" y="28" width="34" height="59" rx="3" fill="rgba(255,255,255,0.6)"/>
        </svg>
      </div>
      <h2>Conhecimento sempre ao seu alcance.</h2>
      <p class="sub">Sem acesso à conta? A biblioteca te ajuda a recuperar em poucos passos.</p>
    </div>

    <div class="auth-form-wrap">
      <div class="login-card" style="max-width:440px;">
        <div class="brand-row-mobile"><?= bibliotechLogo(30) ?> <strong>Bibliotech</strong></div>
        <div class="eyebrow">Estante Digital</div>
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
  </div>
</body>
</html>
