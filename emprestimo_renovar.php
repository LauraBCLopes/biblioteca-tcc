<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$id = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
$stmt->execute(['id' => $id]);
$emprestimo = $stmt->fetch();

if (!$emprestimo) {
    flash('Empréstimo não encontrado.', 'erro');
    header('Location: emprestimos.php');
    exit;
}
if (!isAdmin() && (int) $emprestimo['leitor_id'] !== (int) adminAtual()['id']) {
    flash('Você só pode renovar os seus próprios empréstimos.', 'erro');
    header('Location: emprestimos.php');
    exit;
}
if ($emprestimo['status'] === 'devolvido') {
    flash('Este empréstimo já foi devolvido.', 'erro');
    header('Location: emprestimos.php');
    exit;
}
if ($emprestimo['status'] === 'atrasado') {
    flash('Empréstimos atrasados não podem ser renovados. Registre a devolução.', 'erro');
    header('Location: emprestimos.php');
    exit;
}
if ((int) $emprestimo['renovacoes'] >= 2) {
    flash('Limite de 2 renovações já atingido para este empréstimo.', 'erro');
    header('Location: emprestimos.php');
    exit;
}

// nova data calculada no PHP (não em SQL) para funcionar igual em MySQL e Postgres
$novaPrevista = date('Y-m-d', strtotime($emprestimo['data_prevista'] . ' +7 days'));

$stmt = $pdo->prepare('UPDATE emprestimos
    SET data_prevista = :nova_prevista, renovacoes = renovacoes + 1
    WHERE id = :id');
$stmt->execute(['nova_prevista' => $novaPrevista, 'id' => $id]);

flash('Empréstimo renovado por mais 7 dias.');
header('Location: emprestimos.php');
exit;