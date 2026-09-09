<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM reservas WHERE id = :id');
$stmt->execute(['id' => $id]);
$reserva = $stmt->fetch();

if (!$reserva || $reserva['status'] !== 'pendente') {
    flash('Reserva não encontrada ou já finalizada.', 'erro');
    header('Location: reservas.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT exemplares_disponiveis, titulo FROM livros WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $reserva['livro_id']]);
    $livro = $stmt->fetch();

    $stmt = $pdo->prepare('SELECT status, nome FROM leitores WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $reserva['leitor_id']]);
    $leitor = $stmt->fetch();

    if (!$livro || (int) $livro['exemplares_disponiveis'] <= 0) {
        throw new RuntimeException('Este livro não possui exemplares disponíveis no momento.');
    }
    if (!$leitor || $leitor['status'] !== 'ativo') {
        throw new RuntimeException('Este leitor está bloqueado e não pode retirar livros.');
    }

    $stmt = $pdo->prepare('UPDATE livros SET exemplares_disponiveis = exemplares_disponiveis - 1 WHERE id = :id');
    $stmt->execute(['id' => $reserva['livro_id']]);

    $stmt = $pdo->prepare('INSERT INTO emprestimos
        (livro_id, leitor_id, data_emprestimo, data_prevista, status, renovacoes)
        VALUES (:livro_id, :leitor_id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 14 DAY), \'ativo\', 0)');
    $stmt->execute(['livro_id' => $reserva['livro_id'], 'leitor_id' => $reserva['leitor_id']]);

    $stmt = $pdo->prepare("UPDATE reservas SET status = 'atendida' WHERE id = :id");
    $stmt->execute(['id' => $id]);

    $pdo->commit();
    flash("Reserva atendida: empréstimo de \"{$livro['titulo']}\" registrado para {$leitor['nome']}.");
} catch (Throwable $ex) {
    $pdo->rollBack();
    flash($ex->getMessage(), 'erro');
}

header('Location: reservas.php');
exit;
