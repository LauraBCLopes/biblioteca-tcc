<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: livros.php');
    exit;
}

$pdo = getConexao();

$id              = !empty($_POST['id']) ? (int) $_POST['id'] : null;
$titulo          = trim($_POST['titulo'] ?? '');
$autor           = trim($_POST['autor'] ?? '');
$categoriaId     = (int) ($_POST['categoria_id'] ?? 0);
$anoPublicacao   = $_POST['ano_publicacao'] !== '' ? (int) $_POST['ano_publicacao'] : null;
$exemplaresTotal = (int) ($_POST['exemplares_total'] ?? 0);

// --- validação básica ---
if ($titulo === '' || $autor === '' || $categoriaId <= 0 || $exemplaresTotal < 1) {
    flash('Preencha corretamente todos os campos obrigatórios.', 'erro');
    header('Location: livros.php' . ($id ? "?editar=$id" : ''));
    exit;
}

/**
 * Gera um código de chamada único, ex: RO-482.7
 * baseado na sigla da categoria.
 */
function gerarCodigo(PDO $pdo, int $categoriaId): string
{
    $stmt = $pdo->prepare('SELECT slug FROM categorias WHERE id = :id');
    $stmt->execute(['id' => $categoriaId]);
    $slug = $stmt->fetchColumn();

    $prefixos = [
        'romance'            => 'RO',
        'ficcao-cientifica'  => 'FC',
        'educacional'        => 'ED',
        'obras-brasileiras'  => 'BR',
    ];
    $prefixo = $prefixos[$slug] ?? 'XX';

    do {
        $codigo = $prefixo . '-' . random_int(100, 999) . '.' . random_int(0, 9);
        $chk = $pdo->prepare('SELECT COUNT(*) FROM livros WHERE codigo = :codigo');
        $chk->execute(['codigo' => $codigo]);
    } while ($chk->fetchColumn() > 0);

    return $codigo;
}

if ($id) {
    // ---------- EDIÇÃO ----------
    $stmt = $pdo->prepare('SELECT exemplares_total, exemplares_disponiveis FROM livros WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $atual = $stmt->fetch();

    if (!$atual) {
        flash('Livro não encontrado.', 'erro');
        header('Location: livros.php');
        exit;
    }

    $emprestadosAgora = $atual['exemplares_total'] - $atual['exemplares_disponiveis'];

    if ($exemplaresTotal < $emprestadosAgora) {
        flash("Não é possível definir $exemplaresTotal exemplares: há $emprestadosAgora emprestado(s) no momento.", 'erro');
        header("Location: livros.php?editar=$id");
        exit;
    }

    $novaDisponibilidade = $exemplaresTotal - $emprestadosAgora;

    $stmt = $pdo->prepare('UPDATE livros
        SET titulo = :titulo, autor = :autor, categoria_id = :categoria_id,
            ano_publicacao = :ano, exemplares_total = :total, exemplares_disponiveis = :disp
        WHERE id = :id');
    $stmt->execute([
        'titulo'      => $titulo,
        'autor'       => $autor,
        'categoria_id'=> $categoriaId,
        'ano'         => $anoPublicacao,
        'total'       => $exemplaresTotal,
        'disp'        => $novaDisponibilidade,
        'id'          => $id,
    ]);

    flash('Livro atualizado com sucesso.');
} else {
    // ---------- CADASTRO ----------
    $codigo = gerarCodigo($pdo, $categoriaId);

    $stmt = $pdo->prepare('INSERT INTO livros
        (codigo, titulo, autor, categoria_id, ano_publicacao, exemplares_total, exemplares_disponiveis)
        VALUES (:codigo, :titulo, :autor, :categoria_id, :ano, :total, :total)');
    $stmt->execute([
        'codigo'      => $codigo,
        'titulo'      => $titulo,
        'autor'       => $autor,
        'categoria_id'=> $categoriaId,
        'ano'         => $anoPublicacao,
        'total'       => $exemplaresTotal,
    ]);

    flash('Livro adicionado ao acervo.');
}

header('Location: livros.php');
exit;
