<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: reservas.php');
    exit;
}

$pdo = getConexao();

$livroId  = (int) ($_POST['livro_id'] ?? 0);
// aluno só pode reservar para si mesmo, mesmo que tente enviar outro leitor_id
$leitorId = isAdmin() ? (int) ($_POST['leitor_id'] ?? 0) : (int) adminAtual()['id'];

if ($livroId <= 0 || $leitorId <= 0) {
    flash('Selecione um livro e um leitor.', 'erro');
    header('Location: reservas.php');
    exit;
}

$stmt = $pdo->prepare('SELECT titulo FROM livros WHERE id = :id');
$stmt->execute(['id' => $livroId]);
$livro = $stmt->fetch();

$stmt = $pdo->prepare("SELECT nome, status FROM leitores WHERE id = :id");
$stmt->execute(['id' => $leitorId]);
$leitor = $stmt->fetch();

if (!$livro) {
    flash('Livro não encontrado.', 'erro');
    header('Location: reservas.php');
    exit;
}
if (!$leitor || $leitor['status'] !== 'ativo') {
    flash('Este leitor está bloqueado e não pode fazer reservas.', 'erro');
    header('Location: reservas.php');
    exit;
}

// evita reserva duplicada do mesmo leitor para o mesmo livro
$stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas
    WHERE livro_id = :livro_id AND leitor_id = :leitor_id AND status = 'pendente'");
$stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);
if ($stmt->fetchColumn() > 0) {
    flash('Este leitor já possui uma reserva pendente para este livro.', 'erro');
    header('Location: reservas.php');
    exit;
}

// evita reserva de um livro que o leitor já está com o empréstimo em aberto
$stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
    WHERE livro_id = :livro_id AND leitor_id = :leitor_id AND status != 'devolvido'");
$stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);
if ($stmt->fetchColumn() > 0) {
    flash('Este leitor já está com um exemplar deste livro emprestado.', 'erro');
    header('Location: reservas.php');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO reservas (livro_id, leitor_id, data_reserva, status)
    VALUES (:livro_id, :leitor_id, CURDATE(), \'pendente\')');
$stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);

flash("Reserva registrada para {$leitor['nome']}.");
header('Location: reservas.php');
exit;
