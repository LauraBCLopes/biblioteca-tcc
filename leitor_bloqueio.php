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

$stmt = $pdo->prepare('SELECT * FROM leitores WHERE id = :id');
$stmt->execute(['id' => $id]);
$leitor = $stmt->fetch();

if (!$leitor) {
    flash('Leitor não encontrado.', 'erro');
    header('Location: leitores.php');
    exit;
}

if ($leitor['status'] === 'bloqueado') {
    // Só desbloqueia se não houver empréstimo atrasado em aberto
    // (a data prevista já passou e ainda não foi devolvido).
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
        WHERE leitor_id = :id AND status != 'devolvido' AND data_prevista < :hoje");
    $stmt->execute(['id' => $id, 'hoje' => date('Y-m-d')]);

    if ($stmt->fetchColumn() > 0) {
        flash('Este leitor ainda possui empréstimo(s) atrasado(s). Registre a devolução antes de desbloquear.', 'erro');
        header('Location: leitores.php');
        exit;
    }

    $novoStatus = 'ativo';
    $msg = 'Leitor desbloqueado.';
} else {
    $novoStatus = 'bloqueado';
    $msg = 'Leitor bloqueado manualmente.';
}

$stmt = $pdo->prepare('UPDATE leitores SET status = :status WHERE id = :id');
$stmt->execute(['status' => $novoStatus, 'id' => $id]);

flash($msg);
header('Location: leitores.php');
exit;
