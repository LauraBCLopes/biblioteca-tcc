<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();
$id = (int) ($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: livros.php');
    exit;
}

// Um livro que já foi emprestado alguma vez (mesmo devolvido) não pode ser
// removido — isso preservaria o histórico de circulação. A constraint
// fk_emp_livro (ON DELETE RESTRICT) garante isso no banco; aqui checamos
// antes para dar uma mensagem amigável ao usuário.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM emprestimos WHERE livro_id = :id');
$stmt->execute(['id' => $id]);

if ($stmt->fetchColumn() > 0) {
    flash('Este livro possui histórico de empréstimos e não pode ser removido do acervo.', 'erro');
    header('Location: livros.php');
    exit;
}

try {
    $stmt = $pdo->prepare('DELETE FROM livros WHERE id = :id');
    $stmt->execute(['id' => $id]);
    flash('Livro removido do acervo.');
} catch (PDOException $e) {
    flash('Não foi possível remover este livro (ele está referenciado em outro registro).', 'erro');
}

header('Location: livros.php');
exit;
