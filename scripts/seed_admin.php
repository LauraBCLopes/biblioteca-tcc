<?php
/**
 * Cria o administrador inicial do sistema.
 * Rode uma vez pelo terminal, dentro da pasta do projeto:
 *
 *   php scripts/seed_admin.php
 *
 * Depois é só fazer login em login.php com os dados informados aqui.
 */

require_once __DIR__ . '/../config/database.php';

echo "=== Criação do administrador inicial ===\n";

echo "Nome completo: ";
$nome = trim(fgets(STDIN));

echo "Usuário de login (sem espaços): ";
$usuario = trim(fgets(STDIN));

echo "Senha: ";
$senha = trim(fgets(STDIN));

if ($nome === '' || $usuario === '' || $senha === '') {
    die("Todos os campos são obrigatórios.\n");
}

$pdo = getConexao();

// Evita duplicar se o script rodar mais de uma vez
$check = $pdo->prepare('SELECT id FROM administradores WHERE usuario = :usuario');
$check->execute(['usuario' => $usuario]);
if ($check->fetch()) {
    die("Já existe um administrador com esse usuário.\n");
}

$hash = password_hash($senha, PASSWORD_DEFAULT);

$stmt = $pdo->prepare('INSERT INTO administradores (nome, usuario, senha_hash) VALUES (:nome, :usuario, :hash)');
$stmt->execute([
    'nome'    => $nome,
    'usuario' => $usuario,
    'hash'    => $hash,
]);

echo "Administrador '$usuario' criado com sucesso! Você já pode fazer login.\n";
