<?php

declare(strict_types=1);

namespace Type\Orm\Pgsql;

use PDO;
use PDOException;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;

/** PostgreSQL 驱动配置与会话初始化；连接生命周期交由 ORM 资源池管理。 */
final class PgsqlDriver implements Driver
{
    private string $dsn;
    private string $username;
    private string $password;
    private array $identity;
    private ?string $schema;
    private ?string $databaseRole;
    private ?string $caFile;

    /**
     * 保存端点配置并验证连接身份，不在构造阶段建立数据库连接。
     *
     * schema 与数据库角色显式声明；CA 非空时要求 verify-full。
     * role 为 writer 或 reader；generation 用于凭据/配置轮换，不代表业务数据版本。
     *
     * @throws DatabaseException 地址、角色、代次或文件配置无效。
     */
    public function __construct(
        string $host,
        int $port,
        string $database,
        string $username,
        #[\SensitiveParameter] string $password,
        int $generation = 1,
        string $role = 'writer',
        ?string $schema = null,
        ?string $databaseRole = null,
        ?string $caFile = null
    ) {
        if ($port < 1 || $port > 65535 || $host === '' || $database === '' || preg_match('/[;\s\x00]/', $host . $database)
            || $generation < 1 || !in_array($role, ['reader', 'writer'], true)
            || ($schema !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $schema))
            || ($databaseRole !== null && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $databaseRole))
            || ($caFile !== null && (!is_file($caFile) || !is_readable($caFile) || preg_match('/[;\x00]/', $caFile)))) {
            throw new DatabaseException('PostgreSQL 连接配置无效');
        }
        $this->dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';connect_timeout=5;sslmode=' . ($caFile === null ? 'disable' : 'verify-full');
        if ($caFile !== null) {
            $this->dsn .= ';sslrootcert=' . $caFile;
        }
        $this->username = $username;
        $this->password = $password;
        $this->schema = $schema;
        $this->databaseRole = $databaseRole;
        $this->caFile = $caFile;
        $this->identity = ['driver' => 'pgsql', 'endpoint' => $host . ':' . $port, 'database' => $database, 'username' => $username,
            'credential-generation' => $generation, 'role' => $role, 'tls' => $caFile === null ? 'disabled' : 'verify-full',
            'ca-sha256' => $caFile === null ? '' : hash_file('sha256', $caFile),
            'session' => ['schema' => $schema, 'database-role' => $databaseRole, 'timezone' => 'UTC', 'date-style' => 'ISO, YMD']];
    }

    /** 返回 PostgreSQL 对应的固定方言名称。 */
    public function name(): string
    {
        return 'pgsql';
    }

    /**
     * 返回当前端点与会话策略身份，供池隔离及凭据代次核对。
     *
     * @return array<string, mixed> 包含驱动、端点、逻辑库、角色与凭据代次，不包含密码。
     */
    public function identity(): array
    {
        return $this->identity;
    }

    /**
     * 创建新 PDO 并确认驱动要求的会话基线；失败向上抛出，不回退到其他数据库。
     *
     * @return PDO 由调用者或受管 PdoSession 拥有的真实连接。
     * @throws DatabaseException 扩展、连接或会话初始化不满足约定。
     */
    public function connect(): PDO
    {
        if (!extension_loaded('pdo_pgsql')) {
            throw new DatabaseException('缺少已选择的 pdo_pgsql 扩展');
        }
        try {
            if ($this->caFile !== null && hash_file('sha256', $this->caFile) !== $this->identity['ca-sha256']) {
                throw new DatabaseException('PostgreSQL TLS 配置已变化，需要新连接身份');
            }
            $pdo = new PDO(
                $this->dsn,
                $this->username,
                $this->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $this->initialize($pdo);
            return $pdo;
        } catch (PDOException $error) {
            throw new DatabaseException('PostgreSQL 连接失败', 0, $error);
        }
    }

    /** @internal PostgreSQL 官方 DISCARD ALL 清除角色、变量、临时对象、通知及会话锁。 */
    public function reset(PDO $pdo): bool
    {
        if ($pdo->inTransaction()) {
            return false;
        }
        $pdo->exec('DISCARD ALL');
        $this->initialize($pdo);
        return true;
    }

    private function initialize(PDO $pdo): void
    {
        $pdo->exec("SET TIME ZONE 'UTC'");
        $pdo->exec('SET DateStyle TO ISO, YMD');
        if ($this->databaseRole !== null) {
            $pdo->exec('SET ROLE "' . $this->databaseRole . '"');
        }
        if ($this->schema !== null) {
            $pdo->exec('SET search_path TO "' . $this->schema . '"');
        }
        $pdo->exec('SET default_transaction_read_only = ' . ($this->identity['role'] === 'reader' ? 'ON' : 'OFF'));
    }
}
