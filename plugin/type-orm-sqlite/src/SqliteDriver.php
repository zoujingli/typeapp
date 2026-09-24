<?php

declare(strict_types=1);

namespace Type\Orm\Sqlite;

use PDO;
use PDOException;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;

/** SQLite 驱动配置与会话初始化；连接生命周期交由 ORM 资源池管理。 */
final class SqliteDriver implements Driver
{
    private string $filename;
    private int $busyMilliseconds;
    private bool $wal;
    private string $role;
    private int $generation;
    private string $memoryIdentity;

    /**
     * 保存端点配置并验证连接身份，不在构造阶段建立数据库连接。
     *
     * 文件路径须在调用时解析为绝对路径，父目录须存在；busyMilliseconds 单位毫秒，范围 0 至 60000。
     * role 为 writer 或 reader；generation 用于凭据/配置轮换，不代表业务数据版本。
     *
     * @throws DatabaseException 地址、角色、代次或文件配置无效。
     */
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

    /** 返回 SQLite 对应的固定方言名称。 */
    public function name(): string
    {
        return 'sqlite';
    }

    /** @internal 任意 PRAGMA、临时对象与附加库无法完整重置时关闭会话。 */
    public function reset(PDO $pdo): bool
    {
        return false;
    }

    /**
     * 返回当前端点与会话策略身份，供池隔离及凭据代次核对。
     *
     * @return array<string, mixed> 包含驱动、端点、逻辑库、角色与凭据代次，不包含密码。
     */
    public function identity(): array
    {
        return ['driver' => 'sqlite', 'endpoint' => 'local', 'database' => $this->filename === ':memory:' ? ':memory:' . $this->memoryIdentity : $this->filename,
            'username' => '', 'credential-generation' => $this->generation, 'role' => $this->role, 'tls' => 'not-applicable',
            'session' => ['foreign-keys' => true, 'busy-milliseconds' => $this->busyMilliseconds, 'wal' => $this->wal]];
    }

    /**
     * 创建新 PDO 并确认驱动要求的会话基线；失败向上抛出，不回退到其他数据库。
     *
     * @return PDO 由调用者或受管 PdoSession 拥有的真实连接。
     * @throws DatabaseException 扩展、连接或会话初始化不满足约定。
     */
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
