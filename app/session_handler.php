<?php
/**
 * Database Session Handler for Serverless Environments
 * Persists PHP session data in MySQL/TiDB so sessions survive across isolated Lambda containers.
 */
declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    private function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = db();
        }
        return $this->pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $stmt = $this->getPdo()->prepare(
                'SELECT session_data FROM app_sessions WHERE id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
            );
            $stmt->execute([$id]);
            $data = $stmt->fetchColumn();
            return is_string($data) ? $data : '';
        } catch (Throwable $e) {
            error_log('Database session read failed: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $maxLifetime = (int) ini_get('session.gc_maxlifetime');
            if ($maxLifetime < 3600) {
                $maxLifetime = 86400; // 24 hours
            }
            $stmt = $this->getPdo()->prepare(
                'INSERT INTO app_sessions (id, session_data, expires_at, updated_at)
                 VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), expires_at = VALUES(expires_at), updated_at = UTC_TIMESTAMP()'
            );
            return $stmt->execute([$id, $data, $maxLifetime]);
        } catch (Throwable $e) {
            error_log('Database session write failed: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try {
            $stmt = $this->getPdo()->prepare('DELETE FROM app_sessions WHERE id = ?');
            return $stmt->execute([$id]);
        } catch (Throwable $e) {
            error_log('Database session destroy failed: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $stmt = $this->getPdo()->prepare('DELETE FROM app_sessions WHERE expires_at < UTC_TIMESTAMP()');
            $stmt->execute();
            return (int) $stmt->rowCount();
        } catch (Throwable $e) {
            return false;
        }
    }
}
