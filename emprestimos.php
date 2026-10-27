<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$pdo = getConexao();
$usuario = adminAtual();

// ---------- registrar novo empréstimo — só admin ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireAdmin();

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
}

// Por quanto tempo depois de registrar a devolução ainda dá para desfazer (em segundos).
const PRAZO_DESFAZER_DEVOLUCAO = 600;

// ---------- devolver — só admin ----------
if (!empty($_GET['devolver'])) {
    requireAdmin();

    $idDevolver = (int) $_GET['devolver'];

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idDevolver]);
    $emprestimo = $stmt->fetch();

    if (!$emprestimo || $emprestimo['status'] === 'devolvido') {
        flash('Empréstimo não encontrado ou já devolvido.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    $stmt = $pdo->prepare('SELECT status FROM leitores WHERE id = :id');
    $stmt->execute(['id' => $emprestimo['leitor_id']]);
    $statusLeitorAntes = $stmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE emprestimos
            SET status = 'devolvido', data_devolucao = :hoje, devolucao_solicitada = FALSE
            WHERE id = :id");
        $stmt->execute(['hoje' => date('Y-m-d'), 'id' => $idDevolver]);

        $stmt = $pdo->prepare('UPDATE livros
            SET exemplares_disponiveis = LEAST(exemplares_total, exemplares_disponiveis + 1)
            WHERE id = :id');
        $stmt->execute(['id' => $emprestimo['livro_id']]);

        // Se o leitor estava bloqueado e não tem mais nenhum outro atraso em aberto, desbloqueia
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
            WHERE leitor_id = :leitor_id AND id != :id AND status = 'atrasado'");
        $stmt->execute(['leitor_id' => $emprestimo['leitor_id'], 'id' => $idDevolver]);
        $outroAtraso = $stmt->fetchColumn() > 0;

        if (!$outroAtraso) {
            $stmt = $pdo->prepare("UPDATE leitores SET status = 'ativo' WHERE id = :id AND status = 'bloqueado'");
            $stmt->execute(['id' => $emprestimo['leitor_id']]);
        }

        $pdo->commit();

        // Guarda como estava antes, para o botão "Desfazer"
        $_SESSION['ultima_devolucao'] = [
            'id'            => $idDevolver,
            'status'        => $emprestimo['status'],
            'leitor_status' => $statusLeitorAntes,
            'solicitada'    => bdBool($emprestimo['devolucao_solicitada']),
            'em'            => time(),
        ];
        $acaoDesfazer = ['texto' => 'Desfazer', 'url' => 'emprestimos.php?desfazer_devolucao=' . $idDevolver];

        // Avisa se há reserva pendente para este título (atendida manualmente em reservas.php)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE livro_id = :livro_id AND status = 'pendente'");
        $stmt->execute(['livro_id' => $emprestimo['livro_id']]);
        $temReserva = $stmt->fetchColumn() > 0;

        flash('Devolução registrada.' . ($temReserva ? ' Há uma reserva pendente para este título.' : ''), $temReserva ? 'erro' : 'ok', $acaoDesfazer);
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash('Não foi possível registrar a devolução: ' . $ex->getMessage(), 'erro');
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- aluno avisa que devolveu o livro (o admin confirma depois) ----------
if (!empty($_GET['avisar_devolucao'])) {
    $idAvisar = (int) $_GET['avisar_devolucao'];

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idAvisar]);
    $emprestimo = $stmt->fetch();

    if (isAdmin()) {
        flash('Administradores registram a devolução direto, pelo botão "Devolver".', 'erro');
    } elseif (!$emprestimo || (int) $emprestimo['leitor_id'] !== (int) $usuario['id']) {
        flash('Você só pode devolver os seus próprios empréstimos.', 'erro');
    } elseif ($emprestimo['status'] === 'devolvido') {
        flash('Este empréstimo já foi devolvido.', 'erro');
    } else {
        $stmt = $pdo->prepare('UPDATE emprestimos SET devolucao_solicitada = TRUE WHERE id = :id');
        $stmt->execute(['id' => $idAvisar]);
        flash('Devolução avisada. Assim que a biblioteca confirmar o recebimento, o empréstimo será encerrado.');
    }

    // volta para a tela onde o botão foi clicado (início ou página do livro)
    $voltar = $_GET['voltar'] ?? '';
    if ($voltar === 'inicio') {
        header('Location: index.php');
    } elseif ($voltar === 'livro' && $emprestimo) {
        header('Location: livros.php?ver=' . (int) $emprestimo['livro_id']);
    } else {
        header('Location: emprestimos.php');
    }
    exit;
}

// ---------- admin recusa o aviso de devolução (o livro não chegou) ----------
if (!empty($_GET['recusar_devolucao'])) {
    requireAdmin();

    $stmt = $pdo->prepare("UPDATE emprestimos SET devolucao_solicitada = FALSE
        WHERE id = :id AND status != 'devolvido'");
    $stmt->execute(['id' => (int) $_GET['recusar_devolucao']]);
    flash('Aviso de devolução recusado: o empréstimo continua em aberto.');

    header('Location: emprestimos.php');
    exit;
}

// ---------- desfazer a última devolução — só admin ----------
if (!empty($_GET['desfazer_devolucao'])) {
    requireAdmin();

    $idDesfazer = (int) $_GET['desfazer_devolucao'];
    $ultima = $_SESSION['ultima_devolucao'] ?? null;
    unset($_SESSION['ultima_devolucao']);

    if (!$ultima || (int) $ultima['id'] !== $idDesfazer || time() - $ultima['em'] > PRAZO_DESFAZER_DEVOLUCAO) {
        flash('Não há devolução recente para desfazer.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $idDesfazer]);
        $emprestimo = $stmt->fetch();
        if (!$emprestimo || $emprestimo['status'] !== 'devolvido') {
            throw new RuntimeException('Este empréstimo não está mais como devolvido.');
        }

        // o exemplar devolvido pode já ter sido emprestado para outra pessoa
        $stmt = $pdo->prepare('SELECT exemplares_disponiveis FROM livros WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $emprestimo['livro_id']]);
        if ((int) $stmt->fetchColumn() <= 0) {
            throw new RuntimeException('Não dá para desfazer: o exemplar devolvido já foi emprestado novamente.');
        }

        // se o aluno tinha avisado a devolução, o aviso volta a ficar pendente
        $stmt = $pdo->prepare('UPDATE emprestimos
            SET status = :status, data_devolucao = NULL, devolucao_solicitada = :solicitada
            WHERE id = :id');
        $stmt->execute([
            'status'     => $ultima['status'],
            'solicitada' => !empty($ultima['solicitada']) ? 1 : 0,
            'id'         => $idDesfazer,
        ]);

        $stmt = $pdo->prepare('UPDATE livros SET exemplares_disponiveis = exemplares_disponiveis - 1 WHERE id = :id');
        $stmt->execute(['id' => $emprestimo['livro_id']]);

        // se a devolução tinha desbloqueado o leitor, volta a bloquear
        if ($ultima['leitor_status'] === 'bloqueado') {
            $stmt = $pdo->prepare("UPDATE leitores SET status = 'bloqueado' WHERE id = :id");
            $stmt->execute(['id' => $emprestimo['leitor_id']]);
        }

        $pdo->commit();
        flash('Devolução desfeita: o empréstimo voltou a ficar em aberto.');
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash($ex->getMessage(), 'erro');
    }

    header('Location: emprestimos.php');
    exit;
}

/**
 * Aplica de fato a renovação (+7 dias, zera a solicitação, soma 1 no contador).
 * Empréstimo atrasado (só o admin renova): os 7 dias contam a partir de hoje —
 * somar ao prazo vencido daria uma data que já passou —, ele volta a ficar em
 * dia e o leitor é desbloqueado se não tiver outro atraso.
 * Devolve ['nova_prevista' => 'AAAA-MM-DD', 'leitor_desbloqueado' => bool].
 */
function aplicarRenovacao(PDO $pdo, array $emprestimo): array
{
    // datas calculadas no PHP (não em SQL) para funcionar igual em MySQL e Postgres
    $hoje = date('Y-m-d');
    $prevista = substr($emprestimo['data_prevista'], 0, 10);
    $novaPrevista = date('Y-m-d', strtotime(max($prevista, $hoje) . ' +7 days'));
    $leitorDesbloqueado = false;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE emprestimos
            SET data_prevista = :nova_prevista, renovacoes = renovacoes + 1, renovacao_solicitada = FALSE,
                status = 'ativo'
            WHERE id = :id");
        $stmt->execute(['nova_prevista' => $novaPrevista, 'id' => $emprestimo['id']]);

        if ($emprestimo['status'] === 'atrasado') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM emprestimos
                WHERE leitor_id = :leitor_id AND id != :id AND status = 'atrasado'");
            $stmt->execute(['leitor_id' => $emprestimo['leitor_id'], 'id' => $emprestimo['id']]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $pdo->prepare("UPDATE leitores SET status = 'ativo' WHERE id = :id AND status = 'bloqueado'");
                $stmt->execute(['id' => $emprestimo['leitor_id']]);
                $leitorDesbloqueado = $stmt->rowCount() > 0;
            }
        }

        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }

    return ['nova_prevista' => $novaPrevista, 'leitor_desbloqueado' => $leitorDesbloqueado];
}

/** Mensagem depois de renovar, com o novo prazo (e o desbloqueio, se houve). */
function mensagemRenovacao(string $inicio, array $emprestimo, array $resultado): string
{
    $msg = $inicio . ' Novo prazo: ' . date('d/m/Y', strtotime($resultado['nova_prevista']))
         . ($emprestimo['status'] === 'atrasado' ? ' (7 dias a partir de hoje, pois estava atrasado).' : '.');
    if ($resultado['leitor_desbloqueado']) {
        $msg .= ' O leitor foi desbloqueado.';
    }
    return $msg;
}

// Por quanto tempo depois de renovar (ou pedir renovação) ainda dá para desfazer (em segundos).
const PRAZO_DESFAZER_RENOVACAO = 600;

/**
 * Guarda como o empréstimo estava antes da renovação (ou do pedido do aluno)
 * para o botão "Desfazer" e devolve a ação que vai junto na mensagem.
 * $tipo: 'renovacao' (prazo já estendido) ou 'solicitacao' (aluno só pediu).
 */
function guardarDesfazerRenovacao(array $emprestimo, string $tipo, array $resultado = []): array
{
    $_SESSION['ultima_renovacao'] = [
        'id'                  => (int) $emprestimo['id'],
        'tipo'                => $tipo,
        'status'              => $emprestimo['status'],
        'prevista'            => substr($emprestimo['data_prevista'], 0, 10),
        'nova_prevista'       => $resultado['nova_prevista'] ?? null,
        'renovacoes'          => (int) $emprestimo['renovacoes'],
        'solicitada'          => bdBool($emprestimo['renovacao_solicitada']),
        'leitor_desbloqueado' => !empty($resultado['leitor_desbloqueado']),
        'em'                  => time(),
    ];
    return ['texto' => 'Desfazer', 'url' => 'emprestimos.php?desfazer_renovacao=' . (int) $emprestimo['id']];
}

/** Existe algum OUTRO leitor com reserva pendente para este livro? (bloqueia renovação) */
function temReservaConcorrente(PDO $pdo, int $livroId, int $leitorId): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservas
        WHERE livro_id = :livro_id AND leitor_id != :leitor_id AND status = 'pendente'");
    $stmt->execute(['livro_id' => $livroId, 'leitor_id' => $leitorId]);
    return $stmt->fetchColumn() > 0;
}

// ---------- renovar — admin renova direto; aluno apenas solicita ----------
if (!empty($_GET['renovar'])) {
    $idRenovar = (int) $_GET['renovar'];

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idRenovar]);
    $emprestimo = $stmt->fetch();

    if (!$emprestimo) {
        flash('Empréstimo não encontrado.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if (!isAdmin() && (int) $emprestimo['leitor_id'] !== (int) $usuario['id']) {
        flash('Você só pode renovar os seus próprios empréstimos.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if ($emprestimo['status'] === 'devolvido') {
        flash('Este empréstimo já foi devolvido.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    // aluno só pede renovação antes de vencer; depois do prazo, só a biblioteca renova
    if ($emprestimo['status'] === 'atrasado' && !isAdmin()) {
        flash('Este empréstimo já passou do prazo: só a biblioteca pode renová-lo.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if ((int) $emprestimo['renovacoes'] >= 2) {
        flash('Limite de 2 renovações já atingido para este empréstimo.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    if (isAdmin()) {
        // administrador renova direto (já está aprovando ao fazer isso)
        $resultado = aplicarRenovacao($pdo, $emprestimo);
        flash(mensagemRenovacao('Empréstimo renovado.', $emprestimo, $resultado), 'ok',
              guardarDesfazerRenovacao($emprestimo, 'renovacao', $resultado));
    } else {
        if (temReservaConcorrente($pdo, (int) $emprestimo['livro_id'], (int) $emprestimo['leitor_id'])) {
            flash('Não é possível solicitar renovação: outro leitor está na fila de reserva deste livro.', 'erro');
        } else {
            $stmt = $pdo->prepare('UPDATE emprestimos SET renovacao_solicitada = TRUE WHERE id = :id');
            $stmt->execute(['id' => $idRenovar]);
            flash('Solicitação de renovação enviada. Aguarde a aprovação da biblioteca.', 'ok',
                  guardarDesfazerRenovacao($emprestimo, 'solicitacao'));
        }
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- aprovar/recusar solicitação de renovação — só admin ----------
if (!empty($_GET['aprovar_renovacao']) || !empty($_GET['recusar_renovacao'])) {
    requireAdmin();

    $idSolicitacao = (int) ($_GET['aprovar_renovacao'] ?? $_GET['recusar_renovacao']);

    $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id');
    $stmt->execute(['id' => $idSolicitacao]);
    $emprestimo = $stmt->fetch();

    if (!$emprestimo || !bdBool($emprestimo['renovacao_solicitada'])) {
        flash('Não há solicitação de renovação pendente para este empréstimo.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }

    if (!empty($_GET['aprovar_renovacao'])) {
        if ($emprestimo['status'] === 'devolvido') {
            flash('Este empréstimo já foi devolvido.', 'erro');
            header('Location: emprestimos.php');
            exit;
        }
        if ((int) $emprestimo['renovacoes'] >= 2) {
            flash('Limite de 2 renovações já atingido para este empréstimo.', 'erro');
            header('Location: emprestimos.php');
            exit;
        }
        $resultado = aplicarRenovacao($pdo, $emprestimo);
        flash(mensagemRenovacao('Renovação aprovada.', $emprestimo, $resultado), 'ok',
              guardarDesfazerRenovacao($emprestimo, 'renovacao', $resultado));
    } else {
        $stmt = $pdo->prepare('UPDATE emprestimos SET renovacao_solicitada = FALSE WHERE id = :id');
        $stmt->execute(['id' => $idSolicitacao]);
        flash('Solicitação de renovação recusada.');
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- desfazer a última renovação (ou o pedido de renovação do aluno) ----------
if (!empty($_GET['desfazer_renovacao'])) {
    $idDesfazer = (int) $_GET['desfazer_renovacao'];
    $ultima = $_SESSION['ultima_renovacao'] ?? null;
    unset($_SESSION['ultima_renovacao']);

    if (!$ultima || (int) $ultima['id'] !== $idDesfazer || time() - $ultima['em'] > PRAZO_DESFAZER_RENOVACAO) {
        flash('Não há renovação recente para desfazer.', 'erro');
        header('Location: emprestimos.php');
        exit;
    }
    if ($ultima['tipo'] === 'renovacao') {
        requireAdmin();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT * FROM emprestimos WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $idDesfazer]);
        $emprestimo = $stmt->fetch();
        if (!$emprestimo || $emprestimo['status'] === 'devolvido') {
            throw new RuntimeException('Não dá para desfazer: este empréstimo já foi encerrado.');
        }

        if ($ultima['tipo'] === 'solicitacao') {
            // aluno desiste do pedido — só enquanto a biblioteca ainda não respondeu
            if ((int) $emprestimo['leitor_id'] !== (int) $usuario['id'] || !bdBool($emprestimo['renovacao_solicitada'])) {
                throw new RuntimeException('Não dá para desfazer: a biblioteca já respondeu ao seu pedido de renovação.');
            }
            $stmt = $pdo->prepare('UPDATE emprestimos SET renovacao_solicitada = FALSE WHERE id = :id');
            $stmt->execute(['id' => $idDesfazer]);
            $mensagem = 'Pedido de renovação cancelado.';
        } else {
            // só desfaz se nada mudou depois (ex.: outra renovação em seguida)
            if (substr($emprestimo['data_prevista'], 0, 10) !== $ultima['nova_prevista']
                || (int) $emprestimo['renovacoes'] !== $ultima['renovacoes'] + 1) {
                throw new RuntimeException('Não dá para desfazer: o prazo deste empréstimo mudou depois da renovação.');
            }
            // volta tudo: prazo, contador, situação (atrasado) e, se veio de um
            // pedido do aluno, o pedido volta a aguardar aprovação
            $stmt = $pdo->prepare('UPDATE emprestimos
                SET data_prevista = :prevista, renovacoes = :renovacoes, renovacao_solicitada = :solicitada,
                    status = :status
                WHERE id = :id');
            $stmt->execute([
                'prevista'   => $ultima['prevista'],
                'renovacoes' => $ultima['renovacoes'],
                'solicitada' => $ultima['solicitada'] ? 1 : 0,
                'status'     => $ultima['status'],
                'id'         => $idDesfazer,
            ]);
            // se a renovação tinha desbloqueado o leitor, volta a bloquear
            if (!empty($ultima['leitor_desbloqueado'])) {
                $stmt = $pdo->prepare("UPDATE leitores SET status = 'bloqueado' WHERE id = :id");
                $stmt->execute(['id' => $emprestimo['leitor_id']]);
            }
            $mensagem = 'Renovação desfeita: o prazo voltou para ' . date('d/m/Y', strtotime($ultima['prevista'])) . '.'
                      . ($ultima['status'] === 'atrasado' ? ' O empréstimo voltou a constar como atrasado.' : '')
                      . (!empty($ultima['leitor_desbloqueado']) ? ' O leitor foi bloqueado de novo.' : '')
                      . ($ultima['solicitada'] ? ' O pedido do aluno voltou a aguardar aprovação.' : '');
        }

        $pdo->commit();
        flash($mensagem);
    } catch (Throwable $ex) {
        $pdo->rollBack();
        flash($ex->getMessage(), 'erro');
    }

    header('Location: emprestimos.php');
    exit;
}

// ---------- listagem ----------
$filtro = $_GET['status'] ?? 'todos';

$sql = "SELECT e.*, l.titulo AS livro_titulo, l.codigo AS livro_codigo,
               r.nome AS leitor_nome, r.turma AS leitor_turma
        FROM emprestimos e
        JOIN livros l    ON l.id = e.livro_id
        JOIN leitores r  ON r.id = e.leitor_id";
$paramsTodos = [];
if (!isAdmin()) {
    $sql .= ' WHERE e.leitor_id = :leitor_id';
    $paramsTodos['leitor_id'] = $usuario['id'];
}
$sql .= ' ORDER BY e.data_emprestimo DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($paramsTodos);
$todos = $stmt->fetchAll();

// 'situacao' é calculada aqui (não em SQL) para funcionar igual em MySQL e Postgres
$hojeTs = strtotime(date('Y-m-d'));
foreach ($todos as &$e) {
    if ($e['status'] === 'devolvido') {
        $e['situacao'] = 'devolvido';
    } elseif ($e['status'] === 'atrasado') {
        $e['situacao'] = 'atrasado';
    } else {
        $dias = (int) round((strtotime($e['data_prevista']) - $hojeTs) / 86400);
        $e['situacao'] = $dias <= 3 ? 'aviso' : 'ativo';
    }
}
unset($e);

$emprestimos = $filtro === 'todos'
    ? $todos
    : array_values(array_filter($todos, function ($e) use ($filtro) {
        return $e['situacao'] === $filtro;
    }));

// --- abas do aluno: empréstimos atuais, histórico (devolvidos) e reservas ---
$ABAS_ALUNO = ['emprestimos' => 'Empréstimos', 'historico' => 'Histórico', 'reservas' => 'Reservas'];
$aba = 'emprestimos';
$minhasReservas = [];
if (!isAdmin()) {
    $aba = isset($ABAS_ALUNO[$_GET['aba'] ?? '']) ? $_GET['aba'] : 'emprestimos';

    $emprestimos = array_values(array_filter($todos, function ($e) use ($aba) {
        return $aba === 'historico' ? $e['status'] === 'devolvido' : $e['status'] !== 'devolvido';
    }));
    if ($aba === 'historico') {
        // devolvidos mais recentes primeiro
        usort($emprestimos, function ($a, $b) {
            return [$b['data_devolucao'], $b['id']] <=> [$a['data_devolucao'], $a['id']];
        });
    }

    // posição na fila = quantas reservas pendentes do mesmo livro chegaram antes (ou é esta)
    $stmt = $pdo->prepare("SELECT res.*, l.titulo AS livro_titulo, l.exemplares_disponiveis,
            (SELECT COUNT(*) FROM reservas r2
              WHERE r2.livro_id = res.livro_id AND r2.status = 'pendente'
                AND (r2.data_reserva < res.data_reserva
                     OR (r2.data_reserva = res.data_reserva AND r2.id <= res.id))) AS posicao
        FROM reservas res
        JOIN livros l ON l.id = res.livro_id
        WHERE res.leitor_id = :leitor_id
        ORDER BY CASE WHEN res.status = 'pendente' THEN 0 ELSE 1 END, res.data_reserva DESC, res.id DESC");
    $stmt->execute(['leitor_id' => $usuario['id']]);
    $minhasReservas = $stmt->fetchAll();
}

$emCirculacao = count(array_filter($todos, function ($e) {
    return $e['status'] !== 'devolvido';
}));

// --- opções para os selects do formulário (só usado pelo admin) ---
$livrosDisponiveis = [];
$leitoresAtivos = [];
if (isAdmin()) {
    $livrosDisponiveis = $pdo->query(
        "SELECT id, titulo, exemplares_disponiveis FROM livros WHERE exemplares_disponiveis > 0 ORDER BY titulo"
    )->fetchAll();
    $leitoresAtivos = $pdo->query(
        "SELECT id, nome, turma FROM leitores WHERE status = 'ativo' ORDER BY nome"
    )->fetchAll();
}

$SITUACAO_LABEL = [
    'ativo'     => ['t' => 'Em dia',   'c' => 'ok'],
    'aviso'     => ['t' => 'Vencendo', 'c' => 'warn'],
    'atrasado'  => ['t' => 'Atrasado', 'c' => 'alert'],
    'devolvido' => ['t' => 'Devolvido','c' => 'muted'],
];

$tituloAba = 'Empréstimos';
$paginaAtual = 'emprestimos';
require __DIR__ . '/includes/layout_topo.php';
?>

    <div class="view-head">
      <div>
        <div class="eyebrow">Empréstimos</div>
        <h2><?= isAdmin() ? 'Empréstimos e devoluções' : 'Meus empréstimos' ?></h2>
      </div>
      <div class="mono text-muted" style="font-size:12.5px;"><?= $emCirculacao ?> em circulação</div>
    </div>

    <?php if (isAdmin()): ?>
    <div class="form-panel">
      <h3 style="margin-bottom:16px;">Registrar novo empréstimo</h3>
      <form method="post" action="emprestimos.php">
        <div class="form-grid">
          <div class="field">
            <label>Livro</label>
            <select required name="livro_id">
              <?php if (!$livrosDisponiveis): ?>
                <option value="" disabled selected>Nenhum exemplar disponível</option>
              <?php else: ?>
                <option value="">Selecione</option>
                <?php foreach ($livrosDisponiveis as $l): ?>
                  <option value="<?= $l['id'] ?>"><?= e($l['titulo']) ?> — <?= (int) $l['exemplares_disponiveis'] ?> disp.</option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="field">
            <label>Leitor</label>
            <select required name="leitor_id">
              <?php if (!$leitoresAtivos): ?>
                <option value="" disabled selected>Nenhum leitor ativo disponível</option>
              <?php else: ?>
                <option value="">Selecione</option>
                <?php foreach ($leitoresAtivos as $u): ?>
                  <option value="<?= $u['id'] ?>"><?= e($u['nome']) ?><?= $u['turma'] ? ' — ' . e($u['turma']) : '' ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="field">
            <label>Prazo (dias)</label>
            <input name="prazo" type="number" min="1" value="14">
          </div>
        </div>
        <div style="margin-top:16px;">
          <button class="btn primary" type="submit">Confirmar empréstimo</button>
        </div>
        <div class="hint" style="text-align:left;margin-top:12px;">
          Leitores bloqueados e livros sem exemplares disponíveis não aparecem nas listas acima.
        </div>
      </form>
    </div>
    <?php endif; ?>

    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px;">
      <?php if (isAdmin()): ?>
        <?php
        $abas = ['todos' => 'Todos', 'ativo' => 'Em dia', 'aviso' => 'Vencendo', 'atrasado' => 'Atrasados', 'devolvido' => 'Devolvidos'];
        foreach ($abas as $slug => $label): ?>
          <a href="?status=<?= $slug ?>" class="btn <?= $filtro === $slug ? 'primary' : 'ghost' ?> small"><?= $label ?></a>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($ABAS_ALUNO as $slug => $label): ?>
          <a href="?aba=<?= $slug ?>" class="btn <?= $aba === $slug ? 'primary' : 'ghost' ?> small"><?= $label ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <?php if ($aba === 'reservas'): ?>

    <div class="table-wrap tabela-cartoes">
      <table>
        <thead>
          <tr><th>Livro</th><th>Data da reserva</th><th>Fila</th><th>Status</th><th>Ações</th></tr>
        </thead>
        <tbody>
          <?php if (!$minhasReservas): ?>
            <tr><td colspan="5"><div style="padding:30px;text-align:center;color:var(--texto-secundario);">
              Você não tem reservas. Para reservar, abra um livro em <a href="livros.php">Livros</a>.
            </div></td></tr>
          <?php else: foreach ($minhasReservas as $r): ?>
            <tr>
              <td><a href="livros.php?ver=<?= (int) $r['livro_id'] ?>"><strong><?= e($r['livro_titulo']) ?></strong></a></td>
              <td class="mono"><?= date('d/m/Y', strtotime($r['data_reserva'])) ?></td>
              <td class="mono"><?= $r['status'] === 'pendente' ? (int) $r['posicao'] . 'º na fila' : '—' ?></td>
              <td>
                <?php if ($r['status'] === 'pendente'): ?>
                  <span class="badge warn">Pendente</span>
                  <?php if ((int) $r['exemplares_disponiveis'] > 0): ?><br><span class="badge ok">Exemplar disponível</span><?php endif; ?>
                <?php elseif ($r['status'] === 'atendida'): ?>
                  <span class="badge ok">Atendida</span>
                <?php else: ?>
                  <span class="badge muted">Cancelada</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($r['status'] === 'pendente'): ?>
                  <a class="btn danger small" href="reservas.php?cancelar=<?= (int) $r['id'] ?>&amp;voltar=emprestimos"
                     data-confirmar="Cancelar a reserva de &quot;<?= e($r['livro_titulo']) ?>&quot;? Você sai da fila deste livro."
                     data-confirmar-titulo="Cancelar reserva"
                     data-confirmar-botao="Cancelar reserva">Cancelar</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php else: ?>

    <div class="table-wrap tabela-cartoes">
      <table>
        <thead>
          <tr>
            <th>Livro</th><?php if (isAdmin()): ?><th>Leitor</th><?php endif; ?><th>Empréstimo</th>
            <th><?= $aba === 'historico' ? 'Devolvido em' : 'Devolução prevista' ?></th>
            <th>Situação</th><th>Renovações</th><th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$emprestimos): ?>
            <tr><td colspan="<?= isAdmin() ? 7 : 6 ?>"><div style="padding:30px;text-align:center;color:var(--texto-secundario);">
              <?= $aba === 'historico' ? 'Você ainda não devolveu nenhum livro.' : (isAdmin() ? 'Nenhum empréstimo nesta visualização.' : 'Você não está com nenhum livro emprestado.') ?>
            </div></td></tr>
          <?php else: foreach ($emprestimos as $e):
              $sl = $SITUACAO_LABEL[$e['situacao']];
              // atrasado: só o admin renova (o aluno pede só antes de vencer)
              $podeRenovar = $e['situacao'] !== 'devolvido'
                  && ($e['situacao'] !== 'atrasado' || isAdmin())
                  && (int) $e['renovacoes'] < 2;
          ?>
            <tr id="emprestimo-<?= (int) $e['id'] ?>">
              <td><strong><?= e($e['livro_titulo']) ?></strong><br><span class="mono" style="color:var(--texto-secundario);font-size:11.5px;"><?= e($e['livro_codigo']) ?></span></td>
              <?php if (isAdmin()): ?>
              <td>
                <?= e($e['leitor_nome']) ?>
                <?php if ($e['leitor_turma']): ?><br><span class="text-muted" style="font-size:12px;">Sala <?= e($e['leitor_turma']) ?></span><?php endif; ?>
              </td>
              <?php endif; ?>
              <td class="mono"><?= date('d/m/Y', strtotime($e['data_emprestimo'])) ?></td>
              <td class="mono">
                <?php if ($aba === 'historico'): ?>
                  <?= date('d/m/Y', strtotime($e['data_devolucao'])) ?>
                  <?php if ($e['data_devolucao'] > $e['data_prevista']): ?><br><span class="badge alert">Com atraso</span><?php endif; ?>
                <?php else: ?>
                  <?= date('d/m/Y', strtotime($e['data_prevista'])) ?>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge <?= $sl['c'] ?>"><?= $sl['t'] ?></span>
                <?php if ($e['status'] !== 'devolvido' && bdBool($e['devolucao_solicitada'])): ?>
                  <br><span class="badge warn">Devolução a confirmar</span>
                <?php endif; ?>
              </td>
              <td class="mono">
                <?= (int) $e['renovacoes'] ?>/2
                <?php if (bdBool($e['renovacao_solicitada'])): ?><br><span class="badge warn">Renovação pendente</span><?php endif; ?>
              </td>
              <td><div class="acoes-linha">
                <?php if ($e['status'] !== 'devolvido'): ?>
                  <?php if (isAdmin()): ?>
                    <?php if (bdBool($e['devolucao_solicitada'])): ?>
                      <a class="btn primary small" href="?devolver=<?= (int) $e['id'] ?>">Confirmar devolução</a>
                      <a class="btn danger small" href="?recusar_devolucao=<?= (int) $e['id'] ?>"
                         data-confirmar="O livro não chegou na biblioteca? O aviso do aluno será descartado e o empréstimo continua em aberto."
                         data-confirmar-titulo="Recusar devolução"
                         data-confirmar-botao="Recusar">Não recebido</a>
                    <?php else: ?>
                      <a class="btn primary small" href="?devolver=<?= (int) $e['id'] ?>">Devolver</a>
                    <?php endif; ?>
                    <?php if (bdBool($e['renovacao_solicitada'])): ?>
                      <a class="btn primary small" href="?aprovar_renovacao=<?= (int) $e['id'] ?>">Aprovar renovação</a>
                      <a class="btn danger small" href="?recusar_renovacao=<?= (int) $e['id'] ?>">Recusar</a>
                    <?php elseif ($podeRenovar): ?>
                      <a class="btn ghost small" href="?renovar=<?= (int) $e['id'] ?>">Renovar</a>
                    <?php else: ?>
                      <button class="btn ghost small" disabled>Renovar</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <?php if (bdBool($e['devolucao_solicitada'])): ?>
                      <button class="btn ghost small" disabled>Devolução aguardando confirmação</button>
                    <?php else: ?>
                      <a class="btn primary small" href="?avisar_devolucao=<?= (int) $e['id'] ?>"
                         data-confirmar="Avisar a biblioteca que você devolveu &quot;<?= e($e['livro_titulo']) ?>&quot;? O empréstimo só é encerrado depois que a biblioteca confirmar o recebimento."
                         data-confirmar-titulo="Devolver livro"
                         data-confirmar-botao="Avisar devolução">Devolver</a>
                    <?php endif; ?>
                    <?php if (bdBool($e['renovacao_solicitada'])): ?>
                      <button class="btn ghost small" disabled>Aguardando aprovação</button>
                    <?php elseif ($podeRenovar): ?>
                      <a class="btn ghost small" href="?renovar=<?= (int) $e['id'] ?>">Solicitar renovação</a>
                    <?php else: ?>
                      <button class="btn ghost small" disabled>Solicitar renovação</button>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
              </div></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php endif; ?>

<?php require __DIR__ . '/includes/layout_rodape.php'; ?>
