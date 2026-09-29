<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Se já estiver logado, manda direto para o sistema
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// Domínio fixo por tipo de acesso: quem digitar só o nome de usuário (sem
// "@") tem o e-mail completado automaticamente conforme a aba escolhida.
// Contas antigas, com e-mail em outro domínio, continuam funcionando: basta
// digitar o e-mail completo (com "@") em vez de só o nome de usuário.
const DOMINIO_LOGIN = ['admin' => 'admin.com.br', 'aluno' => 'aluno.com.br'];

$erro = '';
$sucesso = pegarFlash();
$tipoLogin = ($_POST['tipo_login'] ?? 'aluno') === 'admin' ? 'admin' : 'aluno';
$nomeLogin = trim($_POST['nome_login'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $senha = $_POST['senha'] ?? '';

    if ($nomeLogin === '' || $senha === '') {
        $erro = 'Preencha seu usuário e senha.';
    } else {
        $email = montarEmailComDominio($nomeLogin, DOMINIO_LOGIN[$tipoLogin]);

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
<html lang="pt-BR"<?= atributoTema() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — Bibliotech</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css">
</head>
<body>
  <div class="auth-page">
    <div class="auth-center">
      <div class="auth-brand">
        <?= bibliotechLogo(58) ?>
        <div>
          <div class="brand-name">Bibliotech</div>
          <div class="brand-tag">Estante Digital</div>
        </div>
      </div>

      <div class="login-card">
        <h1>Seja bem-vindo!</h1>

        <?php if ($sucesso): ?>
          <div class="alert-box ok"><?= e($sucesso['mensagem']) ?></div>
        <?php endif; ?>
        <?php if ($erro): ?>
          <div class="alert-box"><?= e($erro) ?></div>
        <?php endif; ?>

        <div class="login-tabs" role="tablist">
          <button type="button" class="login-tab<?= $tipoLogin === 'aluno' ? ' active' : '' ?>" data-tipo="aluno">Sou aluno</button>
          <button type="button" class="login-tab<?= $tipoLogin === 'admin' ? ' active' : '' ?>" data-tipo="admin">Sou administrador</button>
        </div>

        <form method="post" novalidate>
          <input type="hidden" name="tipo_login" id="tipo_login" value="<?= e($tipoLogin) ?>">
          <div class="field">
            <label for="nome_login">Usuário</label>
            <div class="login-user-field">
              <input type="text" id="nome_login" name="nome_login" required autofocus
                     placeholder="seu.nome" autocapitalize="off" autocorrect="off"
                     value="<?= e($nomeLogin) ?>">
              <span class="login-suffix" id="login_suffix">@<?= e(DOMINIO_LOGIN[$tipoLogin]) ?></span>
            </div>
          </div>
          <div class="field">
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required>
          </div>
          <button type="submit" class="btn primary full">Entrar</button>
        </form>

        <p class="hint" style="margin-top:14px;">
          Já tinha conta com outro e-mail? Digite o e-mail completo (com "@") no campo de usuário.<br>
          <a href="recuperar-senha.php">Esqueceu sua senha?</a>
        </p>

        <div class="hint">
          Login único para alunos e administradores — o acesso é liberado conforme o seu cadastro.<br>
          Ainda não tem conta? <a href="cadastro.php">Cadastre-se</a>
        </div>
      </div>
    </div>

    <div class="auth-frase">
      <h2>Conhecimento sempre ao seu alcance.</h2>
      <span class="traco" aria-hidden="true"></span>
    </div>
  </div>
<script src="assets/js/interface.js"></script>
</body>
</html>
