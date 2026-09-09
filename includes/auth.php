<?php
/**
 * Funções de autenticação do sistema.
 * Há dois tipos de usuário logado, no mesmo formulário de login (por e-mail):
 *   - 'admin'  -> tabela administradores (bibliotecários, acesso total)
 *   - 'aluno'  -> tabela leitores (autocadastro em cadastro.php, acesso restrito
 *                 ao próprio acervo/empréstimos/reservas)
 * Páginas de qualquer um dos dois tipos chamam requireLogin() no topo.
 * Páginas exclusivas de administrador chamam requireAdmin() no topo.
 */

require_once __DIR__ . '/../config/database.php';

// Fixo aqui (não confio no php.ini do servidor) para "hoje" nunca variar
// entre ambientes — todo o sistema usa data(s) calculadas pelo PHP, nunca
// pelo relógio do banco, justamente para não haver essa divergência.
date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
    // Em produção (Postgres/Vercel), a sessão fica no banco — ver includes/sessao_pg.php.
    // Localmente (MySQL/XAMPP), usa o handler padrão de arquivo do PHP.
    if (DB_DRIVER === 'pgsql') {
        require_once __DIR__ . '/sessao_pg.php';
        session_set_save_handler(new SessaoPostgres(getConexao()), true);
    }
    session_start();
}

/**
 * Tenta autenticar pelo e-mail e senha, procurando primeiro entre os
 * administradores e depois entre os alunos (leitores). Retorna um array
 * ['ok' => bool, 'erro' => string|null].
 */
function autenticar(string $email, string $senha): array
{
    $pdo = getConexao();

    $stmt = $pdo->prepare('SELECT id, nome, usuario, senha_hash FROM administradores WHERE usuario = :email');
    $stmt->execute(['email' => $email]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($senha, $admin['senha_hash'])) {
        iniciarSessao('admin', $admin['id'], $admin['nome'], $admin['usuario']);
        return ['ok' => true, 'erro' => null];
    }

    $stmt = $pdo->prepare('SELECT id, nome, email, senha_hash, status FROM leitores WHERE email = :email');
    $stmt->execute(['email' => $email]);
    $leitor = $stmt->fetch();

    if ($leitor && $leitor['senha_hash'] !== null && password_verify($senha, $leitor['senha_hash'])) {
        if ($leitor['status'] === 'bloqueado') {
            return ['ok' => false, 'erro' => 'Seu cadastro está bloqueado (empréstimo em atraso). Procure a biblioteca para regularizar.'];
        }
        iniciarSessao('aluno', $leitor['id'], $leitor['nome'], $leitor['email']);
        return ['ok' => true, 'erro' => null];
    }

    return ['ok' => false, 'erro' => 'E-mail ou senha inválidos.'];
}

/**
 * Grava os dados da sessão do usuário autenticado (admin ou aluno).
 * Regenera o ID de sessão por segurança (evita fixação de sessão).
 */
function iniciarSessao(string $tipo, int $id, string $nome, string $email): void
{
    session_regenerate_id(true);

    $_SESSION['user_tipo']  = $tipo;
    $_SESSION['user_id']    = $id;
    $_SESSION['user_nome']  = $nome;
    $_SESSION['user_email'] = $email;
}

/**
 * Verifica se há algum usuário logado (admin ou aluno).
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

/**
 * Verifica se o usuário logado é um administrador.
 */
function isAdmin(): bool
{
    return isLoggedIn() && $_SESSION['user_tipo'] === 'admin';
}

/**
 * Verifica se o usuário logado é um aluno (leitor).
 */
function isAluno(): bool
{
    return isLoggedIn() && $_SESSION['user_tipo'] === 'aluno';
}

/**
 * Bloqueia o acesso a quem não estiver logado, redirecionando ao login.
 * Chame no topo de toda página acessível por admin OU aluno.
 */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    atualizarAtrasos();
}

/**
 * Bloqueia o acesso de quem não for administrador.
 * Chame no topo de toda página exclusiva de administrador.
 */
function requireAdmin(): void
{
    requireLogin();

    if (!isAdmin()) {
        flash('Acesso restrito a administradores.', 'erro');
        header('Location: index.php');
        exit;
    }
}

/**
 * Roda a cada carregamento de página protegida: marca como 'atrasado'
 * todo empréstimo ainda 'ativo' cujo prazo já passou, e bloqueia
 * automaticamente o leitor responsável por ele. Não depende de cron —
 * o próprio acesso ao sistema mantém os status em dia.
 */
function atualizarAtrasos(): void
{
    $pdo = getConexao();

    // "hoje" vem do PHP (nunca do relógio do banco) para não haver
    // divergência de fuso horário entre app e banco.
    $stmt = $pdo->prepare("UPDATE emprestimos
        SET status = 'atrasado'
        WHERE status = 'ativo' AND data_prevista < :hoje");
    $stmt->execute(['hoje' => date('Y-m-d')]);

    $pdo->exec("UPDATE leitores
        SET status = 'bloqueado'
        WHERE status = 'ativo' AND id IN (
            SELECT leitor_id FROM emprestimos WHERE status = 'atrasado'
        )");
}

/**
 * Retorna os dados do usuário logado (admin ou aluno), ou null.
 * Mantém a chave 'login' por compatibilidade com o restante do código.
 */
function adminAtual(): ?array
{
    if (!isLoggedIn()) {
        return null;
    }

    return [
        'id'    => $_SESSION['user_id'],
        'nome'  => $_SESSION['user_nome'],
        'login' => $_SESSION['user_email'],
        'tipo'  => $_SESSION['user_tipo'],
    ];
}

/**
 * Encerra a sessão do usuário logado.
 */
function logout(): void
{
    $_SESSION = [];
    session_destroy();
}
