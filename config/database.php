<?php
/**
 * Conexão com o banco de dados usando PDO.
 *
 * As credenciais vêm de variáveis de ambiente — nunca ficam
 * hardcoded aqui, para não serem commitadas no repositório.
 *
 *   DB_DRIVER -> 'mysql' (padrão local, XAMPP/Laragon) ou 'pgsql' (Supabase)
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
 *
 * Em produção (Vercel), essas variáveis são definidas em
 * Project Settings → Environment Variables, usando os dados do
 * Supabase (Session pooler — necessário porque o sistema usa
 * transações com SELECT ... FOR UPDATE).
 *
 * Localmente, se as variáveis não existirem, cai nos valores padrão
 * de desenvolvimento do XAMPP/Laragon (usuário "root", senha vazia).
 */

function envOuPadrao(string $chave, string $padrao): string
{
    $valor = getenv($chave);
    return ($valor !== false && $valor !== '') ? $valor : $padrao;
}

define('DB_DRIVER', envOuPadrao('DB_DRIVER', 'mysql'));
define('DB_HOST',   envOuPadrao('DB_HOST', 'localhost'));
define('DB_PORT',   envOuPadrao('DB_PORT', DB_DRIVER === 'pgsql' ? '5432' : '3306'));
define('DB_NAME',   envOuPadrao('DB_NAME', 'biblioteca'));
define('DB_USER',   envOuPadrao('DB_USER', 'root'));
define('DB_PASS',   envOuPadrao('DB_PASS', ''));

/**
 * Retorna uma instância PDO conectada ao banco.
 * Usamos uma variável estática para reaproveitar a mesma conexão
 * durante toda a requisição (padrão simples de "singleton").
 */
function getConexao(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = DB_DRIVER === 'pgsql'
            ? 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME
            : 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        $opcoes = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opcoes);
        } catch (PDOException $e) {
            // Em produção não exiba $e->getMessage() diretamente ao usuário.
            // Para o TCC, deixamos visível para facilitar a depuração.
            die('Erro ao conectar ao banco de dados: ' . $e->getMessage());
        }
    }

    return $pdo;
}
