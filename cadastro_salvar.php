<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cadastro.php');
    exit;
}

$pdo = getConexao();

$nome           = trim($_POST['nome'] ?? '');
$email          = trim($_POST['email'] ?? '');
$telefone       = trim($_POST['telefone'] ?? '');
$senha          = $_POST['senha'] ?? '';
$confirmarSenha = $_POST['confirmar_senha'] ?? '';

function voltarComErro(string $mensagem, array $valores): void
{
    flash($mensagem, 'erro');
    $_SESSION['cadastro_valores'] = $valores;
    header('Location: cadastro.php');
    exit;
}

$valoresDigitados = ['nome' => $nome, 'email' => $email, 'telefone' => $telefone];

if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    voltarComErro('Preencha o nome e um e-mail válido.', $valoresDigitados);
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
