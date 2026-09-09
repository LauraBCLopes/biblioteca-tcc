<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: leitores.php');
    exit;
}

$pdo = getConexao();

$id       = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$nome     = trim($_POST['nome'] ?? '');
$email    = trim($_POST['email'] ?? '');
$telefone = trim($_POST['telefone'] ?? '');

if ($nome === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('Preencha o nome e um e-mail válido.', 'erro');
    header('Location: leitores.php' . ($id ? "?editar=$id" : ''));
    exit;
}

// e-mail precisa ser único
$stmt = $pdo->prepare('SELECT id FROM leitores WHERE email = :email AND id != :id');
$stmt->execute(['email' => $email, 'id' => $id ?? 0]);
if ($stmt->fetch()) {
    flash('Já existe um leitor cadastrado com esse e-mail.', 'erro');
    header('Location: leitores.php' . ($id ? "?editar=$id" : ''));
    exit;
}

if ($id) {
    $stmt = $pdo->prepare('UPDATE leitores SET nome = :nome, email = :email, telefone = :telefone WHERE id = :id');
    $stmt->execute(['nome' => $nome, 'email' => $email, 'telefone' => $telefone, 'id' => $id]);
    flash('Leitor atualizado com sucesso.');
} else {
    $stmt = $pdo->prepare('INSERT INTO leitores (nome, email, telefone) VALUES (:nome, :email, :telefone)');
    $stmt->execute(['nome' => $nome, 'email' => $email, 'telefone' => $telefone]);
    flash('Leitor cadastrado com sucesso.');
}

header('Location: leitores.php');
exit;
