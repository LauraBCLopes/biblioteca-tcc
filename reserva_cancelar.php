<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

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
if (!isAdmin() && (int) $reserva['leitor_id'] !== (int) adminAtual()['id']) {
    flash('Você só pode cancelar as suas próprias reservas.', 'erro');
    header('Location: reservas.php');
    exit;
}

$stmt = $pdo->prepare("UPDATE reservas SET status = 'cancelada' WHERE id = :id");
$stmt->execute(['id' => $id]);

flash('Reserva cancelada.');
header('Location: reservas.php');
exit;
