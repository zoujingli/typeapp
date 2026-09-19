<?php

declare(strict_types=1);

namespace Type\Orm\Pgsql;

use PDO;
use PDOException;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;

final class PgsqlDriver implements Driver
{
    private string $dsn;
    private string $username;
    private string $password;
    private array $identity;
    private ?string $schema;
    private ?string $databaseRole;
    private ?string $caFile;

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

    public function name(): string
    {
        return 'pgsql';
    }

    public function identity(): array
    {
        return $this->identity;
    }

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
            $pdo->exec("SET TIME ZONE 'UTC'");
            $pdo->exec('SET DateStyle TO ISO, YMD');
            if ($this->databaseRole !== null) {
                $pdo->exec('SET ROLE "' . $this->databaseRole . '"');
            }
            if ($this->schema !== null) {
                $pdo->exec('SET search_path TO "' . $this->schema . '"');
            }
            if ($this->identity['role'] === 'reader') {
                $pdo->exec('SET default_transaction_read_only = ON');
            }
            return $pdo;
        } catch (PDOException $error) {
            throw new DatabaseException('PostgreSQL 连接失败', 0, $error);
        }
    }
}
