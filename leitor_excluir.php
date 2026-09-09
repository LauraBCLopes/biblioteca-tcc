<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: leitores.php');
    exit;
}

// Assim como nos livros, um leitor que já tomou algum empréstimo (mesmo
// devolvido) não pode ser removido — preserva o histórico de circulação.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM emprestimos WHERE leitor_id = :id');
$stmt->execute(['id' => $id]);

if ($stmt->fetchColumn() > 0) {
    flash('Este leitor possui histórico de empréstimos e não pode ser removido.', 'erro');
    header('Location: leitores.php');
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM leitores WHERE id = :id');
    $stmt->execute(['id' => $id]);
    flash('Leitor removido do cadastro.');
} catch (PDOException $e) {
    flash('Não foi possível remover este leitor (ele está referenciado em outro registro).', 'erro');
}

header('Location: leitores.php');
exit;
