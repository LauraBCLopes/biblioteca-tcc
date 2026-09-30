<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Se já estiver logado, manda direto para o sistema
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

function voltarComErro(string $mensagem, array $valores): void
{
    flash($mensagem, 'erro');
    $_SESSION['cadastro_valores'] = $valores;
    header('Location: cadastro.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getConexao();

    $nome           = trim($_POST['nome'] ?? '');
    $usuario        = trim($_POST['usuario'] ?? '');
    $telefone       = trim($_POST['telefone'] ?? '');
    $senha          = $_POST['senha'] ?? '';
    $confirmarSenha = $_POST['confirmar_senha'] ?? '';

    $email = montarEmailComDominio($usuario, 'aluno.com.br');
    $valoresDigitados = ['nome' => $nome, 'usuario' => $usuario, 'telefone' => $telefone];

    if ($nome === '' || $usuario === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        voltarComErro('Preencha o nome e um usuário/e-mail válido.', $valoresDigitados);
    }
    if (mb_strlen($senha) < 6) {
        voltarComErro('A senha precisa ter pelo menos 6 caracteres.', $valoresDigitados);
    }
    if ($senha !== $confirmarSenha) {
        voltarComErro('As senhas digitadas não são iguais.', $valoresDigitados);
    }

    // e-mail de administrador não pode ser usado para criar conta de aluno
    $stmt = $pdo->prepare('SELECT id FROM administradores WHERE usuario = :email');
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        voltarComErro('Este e-mail já está em uso. Faça login em vez de criar uma conta.', $valoresDigitados);
    }

    $stmt = $pdo->prepare('SELECT id, senha_hash FROM leitores WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $leitorExistente = $stmt->fetch();

    $hash = password_hash($senha, PASSWORD_DEFAULT);

    if ($leitorExistente) {
        if ($leitorExistente['senha_hash'] !== null) {
            // já existe conta com senha para este e-mail
            voltarComErro('Já existe uma conta com este e-mail. Faça login em vez de criar uma conta.', $valoresDigitados);
        }

        // a biblioteca já tinha cadastrado este leitor manualmente, sem senha —
        // este cadastro apenas "assume" a conta, criando o acesso ao sistema.
        $stmt = $pdo->prepare('UPDATE leitores SET nome = :nome, telefone = :telefone, senha_hash = :hash WHERE id = :id');
        $stmt->execute([
            'nome'     => $nome,
            'telefone' => $telefone,
            'hash'     => $hash,
            'id'       => $leitorExistente['id'],
        ]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO leitores (nome, email, telefone, senha_hash)
            VALUES (:nome, :email, :telefone, :hash)');
        $stmt->execute([
            'nome'     => $nome,
            'email'    => $email,
            'telefone' => $telefone,
            'hash'     => $hash,
        ]);
    }

    flash('Conta criada com sucesso! Faça login para continuar.');
    header('Location: login.php');
    exit;
}

$erro = pegarFlash();
$valores = $_SESSION['cadastro_valores'] ?? [];
unset($_SESSION['cadastro_valores']);
?>
<!DOCTYPE html>
<html lang="pt-BR"<?= atributoTema() ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Criar conta — Bibliotech</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/estilo.css?v=<?= VERSAO_ASSETS ?>">
</head>
<body>
  <div class="auth-page">
    <div class="auth-center">
      <div class="auth-brand">
        <div class="brand-name">Bibliotech</div>
        <div class="brand-tag">Estante Digital</div>
      </div>

      <div class="login-card" style="max-width:440px;">
        <h1>Criar conta de aluno</h1>

        <?php if ($erro): ?>
          <div class="alert-box"><?= e($erro['mensagem']) ?></div>
        <?php endif; ?>

        <form method="post" action="cadastro.php" novalidate>
          <div class="field">
            <label for="nome">Nome completo</label>
            <input type="text" id="nome" name="nome" required autofocus value="<?= e($valores['nome'] ?? '') ?>">
          </div>
          <div class="field">
            <label for="usuario">Usuário</label>
            <div class="login-user-field">
              <input type="text" id="usuario" name="usuario" required placeholder="seu.nome"
                     autocapitalize="off" autocorrect="off" value="<?= e($valores['usuario'] ?? '') ?>">
              <span class="login-suffix">@aluno.com.br</span>
            </div>
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
          <button type="submit" class="btn primary full">Criar minha conta</button>
        </form>

        <div class="hint">
          Já tem conta? <a href="login.php">Fazer login</a>
        </div>
      </div>
    </div>

    <div class="auth-frase">
      <h2>Conhecimento sempre ao seu alcance.</h2>
      <span class="traco" aria-hidden="true"></span>
    </div>
  </div>
<script src="assets/js/interface.js?v=<?= VERSAO_ASSETS ?>"></script>
</body>
</html>
