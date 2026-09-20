<?php

declare(strict_types=1);

namespace Type\Orm\Mysql;

use PDO;
use PDOException;
use Type\Orm\DatabaseException;
use Type\Orm\Driver;

final class MysqlDriver implements Driver
{
    private string $host;
    private int $port;
    private string $database;
    private string $username;
    private string $password;
    private int $generation;
    private string $role;
    private ?string $caFile;
    private string $caHash;

    public function __construct(
        string $host,
        int $port,
        string $database,
        string $username,
        #[\SensitiveParameter] string $password,
        int $generation = 1,
        string $role = 'writer',
        ?string $caFile = null
    ) {
        if ($port < 1 || $port > 65535 || $host === '' || $database === '' || preg_match('/[;\x00]/', $host . $database)
            || $generation < 1 || !in_array($role, ['writer', 'reader'], true) || ($caFile !== null && (!is_file($caFile) || !is_readable($caFile)))) {
            throw new DatabaseException('MySQL 连接配置无效');
        }
        $this->host = $host;
        $this->port = $port;
        $this->database = $database;
        $this->username = $username;
        $this->password = $password;
        $this->generation = $generation;
        $this->role = $role;
        $this->caFile = $caFile;
        $this->caHash = $caFile === null ? '' : hash_file('sha256', $caFile);
    }

    public function name(): string
    {
        return 'mysql';
    }

    /** @internal 标准 PDO MySQL 没有完整会话重置接口，归还时退役物理连接。 */
    public function reset(PDO $pdo): bool
    {
        return false;
    }

    public function identity(): array
    {
        return ['driver' => 'mysql', 'endpoint' => $this->host . ':' . $this->port, 'database' => $this->database,
            'username' => $this->username, 'credential-generation' => $this->generation, 'role' => $this->role,
            'tls' => $this->caFile === null ? 'disabled' : 'verify-peer', 'ca-sha256' => $this->caHash,
            'session' => ['timezone' => '+00:00', 'strict' => true]];
    }

    public function connect(): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new DatabaseException('缺少已选择的 pdo_mysql 扩展');
        }
        try {
            $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_TIMEOUT => 5];
            if ($this->caFile !== null) {
                if (hash_file('sha256', $this->caFile) !== $this->caHash) {
                    throw new DatabaseException('MySQL TLS 配置已变化，需要新连接身份');
                }
                $options[\Pdo\Mysql::ATTR_SSL_CA] = $this->caFile;
                $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
            $pdo = new PDO(
                'mysql:host=' . $this->host . ';port=' . $this->port . ';dbname=' . $this->database . ';charset=utf8mb4',
                $this->username,
                $this->password,
                $options
            );
            if ($this->caFile !== null) {
                $tls = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_ASSOC);
                if (!is_array($tls) || ($tls['Value'] ?? '') === '') {
                    throw new DatabaseException('MySQL 未建立要求的 TLS 连接');
                }
            }
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = CONCAT(IF(@@SESSION.sql_mode = '', '', CONCAT(@@SESSION.sql_mode, ',')), 'STRICT_ALL_TABLES')");
            if ($this->role === 'reader') {
                $pdo->exec('SET SESSION TRANSACTION READ ONLY');
            }
            return $pdo;
        } catch (PDOException $error) {
            throw new DatabaseException('MySQL 连接失败', 0, $error);
        }
    }
}
