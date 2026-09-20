<?php

declare(strict_types=1);

namespace Type\Orm\Sqlite;

use PDO;
use PDOException;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;

final class SqliteDriver implements Driver
{
    private string $filename;
    private int $busyMilliseconds;
    private bool $wal;
    private string $role;
    private int $generation;
    private string $memoryIdentity;

    public function __construct(string $filename, int $busyMilliseconds = 1000, bool $wal = true, int $generation = 1, string $role = 'writer')
    {
        if ($filename === '' || str_contains($filename, "\0") || $busyMilliseconds < 0 || $busyMilliseconds > 60000 || $generation < 1 || !in_array($role, ['reader', 'writer'], true)) {
            throw new DatabaseException('SQLite 配置无效');
        }
        $absolute = str_starts_with($filename, '/')
            || (PHP_OS_FAMILY === 'Windows' && preg_match('~^[A-Za-z]:/~D', str_replace('\\', '/', $filename)) === 1);
        if ($filename !== ':memory:' && (!$absolute || !is_dir(dirname($filename)))) {
            throw new DatabaseException('SQLite 文件库需要存在的本地目录与绝对文件路径');
        }
        $this->filename = $filename;
        $this->busyMilliseconds = $busyMilliseconds;
        $this->wal = $wal && $filename !== ':memory:';
        $this->generation = $generation;
        $this->role = $role;
        $this->memoryIdentity = bin2hex(random_bytes(16));
    }

    public function name(): string
    {
        return 'sqlite';
    }

    /** @internal 任意 PRAGMA、临时对象与附加库无法完整重置时关闭会话。 */
    public function reset(PDO $pdo): bool
    {
        return false;
    }

    public function identity(): array
    {
        return ['driver' => 'sqlite', 'endpoint' => 'local', 'database' => $this->filename === ':memory:' ? ':memory:' . $this->memoryIdentity : $this->filename,
            'username' => '', 'credential-generation' => $this->generation, 'role' => $this->role, 'tls' => 'not-applicable',
            'session' => ['foreign-keys' => true, 'busy-milliseconds' => $this->busyMilliseconds, 'wal' => $this->wal]];
    }

    public function connect(): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new DatabaseException('缺少已选择的 pdo_sqlite 扩展');
        }
        try {
            $pdo = new PDO(
                'sqlite:' . $this->filename,
                null,
                null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false]
            );
            $pdo->exec('PRAGMA busy_timeout = ' . $this->busyMilliseconds);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $check = $pdo->query('PRAGMA foreign_keys');
            if ((int) $check->fetchColumn() !== 1) {
                throw new DatabaseException('SQLite 外键检查未启用');
            }
            $check->closeCursor();
            if ($this->wal) {
                $mode = $pdo->query('PRAGMA journal_mode = WAL');
                $actual = (string) $mode->fetchColumn();
                $mode->closeCursor();
                if (strtolower($actual) !== 'wal') {
                    throw new DatabaseException('SQLite 文件库未进入 WAL 模式');
                }
                $pdo->exec('PRAGMA synchronous = FULL');
            }
            if ($this->role === 'reader') {
                $pdo->exec('PRAGMA query_only = ON');
            }

            return $pdo;
        } catch (PDOException $error) {
            throw new DatabaseException('SQLite 连接或初始化失败', 0, $error);
        }
    }
}
