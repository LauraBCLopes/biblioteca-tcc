<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

$pdo = getConexao();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
$stmt->execute(['id' => $id]);
$emprestimo = $stmt->fetch();

if (!$emprestimo || $emprestimo['status'] === 'devolvido') {
    flash('Empréstimo não encontrado ou já devolvido.', 'erro');
    header('Location: emprestimos.php');
    exit;
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("UPDATE emprestimos SET status = 'devolvido', data_devolucao = :hoje WHERE id = :id");
    $stmt->execute(['hoje' => date('Y-m-d'), 'id' => $id]);

    $stmt = $pdo->prepare('UPDATE livros
        SET exemplares_disponiveis = LEAST(exemplares_total, exemplares_disponiveis + 1)
        WHERE id = :id');
    $stmt->execute(['id' => $emprestimo['livro_id']]);

    // Se o leitor estava bloqueado e não tem mais nenhum outro atraso em aberto, desbloqueia
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
        WHERE leitor_id = :leitor_id AND id != :id AND status = 'atrasado'");
    $stmt->execute(['leitor_id' => $emprestimo['leitor_id'], 'id' => $id]);
    $outroAtraso = $stmt->fetchColumn() > 0;

    if (!$outroAtraso) {
        $stmt = $pdo->prepare("UPDATE leitores SET status = 'ativo' WHERE id = :id AND status = 'bloqueado'");
        $stmt->execute(['id' => $emprestimo['leitor_id']]);
    }

    $pdo->commit();

    // Avisa se há reserva pendente para este título (atendida manualmente na Parte 6)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE livro_id = :livro_id AND status = 'pendente'");
    $stmt->execute(['livro_id' => $emprestimo['livro_id']]);
    $temReserva = $stmt->fetchColumn() > 0;

    flash('Devolução registrada.' . ($temReserva ? ' Há uma reserva pendente para este título.' : ''), $temReserva ? 'erro' : 'ok');
} catch (Throwable $ex) {
    $pdo->rollBack();
    flash('Não foi possível registrar a devolução: ' . $ex->getMessage(), 'erro');
}

header('Location: emprestimos.php');
exit;