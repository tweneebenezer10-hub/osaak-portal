<?php
/**
 * Database Session Handler for Serverless Environments
 * Persists PHP session data in MySQL/TiDB so sessions survive across isolated Lambda containers.
 */
declare(strict_types=1);

final class DatabaseSessionHandler implements SessionHandlerInterface
{
    private ?PDO $pdo;
    /** @var array<string, string> */
    private array $loadedData = [];
    /** @var array<string, bool> */
    private array $hasRecord = [];

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

    private function ensureTable(): void
    {
        $this->getPdo()->exec(
            "CREATE TABLE IF NOT EXISTS app_sessions (
                id VARCHAR(128) NOT NULL PRIMARY KEY,
                session_data MEDIUMTEXT NOT NULL,
                expires_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_app_sessions_expires (expires_at)
            ) ENGINE=InnoDB"
        );
    }

    private function isMissingTableError(Throwable $e): bool
    {
        return $e instanceof PDOException
            && (($e->errorInfo[0] ?? '') === '42S02' || (int) ($e->errorInfo[1] ?? 0) === 1146);
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
        $cookieName = session_name();
        if (empty($_COOKIE[$cookieName]) || (string) $_COOKIE[$cookieName] !== $id) {
            $this->loadedData[$id] = '';
            $this->hasRecord[$id] = false;
            return '';
        }
        try {
            $stmt = $this->getPdo()->prepare(
                'SELECT session_data FROM app_sessions WHERE id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
            );
            $stmt->execute([$id]);
            $data = $stmt->fetchColumn();
            if (is_string($data)) {
                $this->loadedData[$id] = $data;
                $this->hasRecord[$id] = true;
                return $data;
            }
            $this->loadedData[$id] = '';
            $this->hasRecord[$id] = false;
            return '';
        } catch (Throwable $e) {
            if ($this->isMissingTableError($e)) {
                try {
                    $this->ensureTable();
                } catch (Throwable $ignore) {}
            } else {
                error_log('Database session read failed: ' . $e->getMessage());
            }
            $this->loadedData[$id] = '';
            $this->hasRecord[$id] = false;
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        $exists = $this->hasRecord[$id] ?? false;
        if ($data === '' && !$exists) {
            return true;
        }
        if ($exists && array_key_exists($id, $this->loadedData) && $data === $this->loadedData[$id]) {
            return true;
        }
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
            $ok = $stmt->execute([$id, $data, $maxLifetime]);
            if ($ok) {
                $this->loadedData[$id] = $data;
                $this->hasRecord[$id] = true;
            }
            return $ok;
        } catch (Throwable $e) {
            if ($this->isMissingTableError($e)) {
                try {
                    $this->ensureTable();
                    $stmt = $this->getPdo()->prepare(
                        'INSERT INTO app_sessions (id, session_data, expires_at, updated_at)
                         VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP())
                         ON DUPLICATE KEY UPDATE session_data = VALUES(session_data), expires_at = VALUES(expires_at), updated_at = UTC_TIMESTAMP()'
                    );
                    $ok = $stmt->execute([$id, $data, 86400]);
                    if ($ok) {
                        $this->loadedData[$id] = $data;
                        $this->hasRecord[$id] = true;
                    }
                    return $ok;
                } catch (Throwable $retryError) {
                    error_log('Database session write retry failed: ' . $retryError->getMessage());
                    return false;
                }
            }
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
