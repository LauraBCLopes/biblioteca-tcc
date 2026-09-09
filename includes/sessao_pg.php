<?php
/**
 * Handler de sessão PHP guardado no banco (tabela `sessoes`).
 *
 * Necessário em produção (Vercel): funções serverless são efêmeras e não
 * compartilham o disco entre requisições, então o handler padrão de sessão
 * em arquivo faz o login "cair" a cada requisição. Guardando a sessão no
 * mesmo Postgres/Supabase usado pelo resto do sistema, ela persiste
 * independente de qual instância da função atendeu a requisição.
 *
 * Só é ativado quando DB_DRIVER = 'pgsql' (produção). Localmente
 * (DB_DRIVER = 'mysql', XAMPP/Laragon) continua usando o handler padrão de
 * arquivo, que funciona bem num servidor Apache tradicional.
 */
class SessaoPostgres implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read($id): string
    {
        $stmt = $this->pdo->prepare('SELECT dados FROM sessoes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $dados = $stmt->fetchColumn();

        return $dados !== false ? $dados : '';
    }

    public function write($id, $dados): bool
    {
        $stmt = $this->pdo->prepare('INSERT INTO sessoes (id, dados, atualizado_em)
            VALUES (:id, :dados, NOW())
            ON CONFLICT (id) DO UPDATE SET dados = :dados, atualizado_em = NOW()');
        return $stmt->execute(['id' => $id, 'dados' => $dados]);
    }

    public function destroy($id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessoes WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function gc($maxLifetime): int|false
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessoes WHERE atualizado_em < NOW() - (:segundos || ' seconds')::interval");
        $stmt->execute(['segundos' => $maxLifetime]);
        return $stmt->rowCount();
    }
}
