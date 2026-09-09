<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: emprestimos.php');
    exit;
}

$pdo = getConexao();

$livroId  = (int) ($_POST['livro_id'] ?? 0);
$leitorId = (int) ($_POST['leitor_id'] ?? 0);
$prazo    = max(1, (int) ($_POST['prazo'] ?? 14));

if ($livroId <= 0 || $leitorId <= 0) {
    flash('Selecione um livro e um leitor.', 'erro');
    header('Location: emprestimos.php');
    exit;
}

try {
    $pdo->beginTransaction();

    // trava a linha do livro para evitar condição de corrida entre dois
    // empréstimos simultâneos do último exemplar disponível
    $stmt = $pdo->prepare('SELECT exemplares_disponiveis, titulo FROM livros WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => $livroId]);
    $livro = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT status, nome FROM leitores WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $leitorId]);
    $leitor = $stmt->fetch();

    if (!$livro || (int) $livro['exemplares_disponiveis'] <= 0) {
        throw new RuntimeException('Este livro não possui exemplares disponíveis.');
    }
    if (!$leitor || $leitor['status'] !== 'ativo') {
        throw new RuntimeException('Este leitor está bloqueado e não pode retirar livros.');
    }

    $stmt = $pdo->prepare('UPDATE livros SET exemplares_disponiveis = exemplares_disponiveis - 1 WHERE id = :id');
    $stmt->execute(['id' => $livroId]);

    // datas calculadas no PHP (não em SQL) para funcionar igual em MySQL e Postgres
    $hoje = date('Y-m-d');
    $prevista = date('Y-m-d', strtotime("+{$prazo} days"));

    $stmt = $pdo->prepare('INSERT INTO emprestimos
        (livro_id, leitor_id, data_emprestimo, data_prevista, status, renovacoes)
        VALUES (:livro_id, :leitor_id, :hoje, :prevista, \'ativo\', 0)');
    $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId, 'hoje' => $hoje, 'prevista' => $prevista]);

    $pdo->commit();
    flash("Empréstimo registrado para {$leitor['nome']}.");
} catch (Throwable $ex) {
    $pdo->rollBack();
    flash($ex->getMessage(), 'erro');
}

header('Location: emprestimos.php');
exit;