<?php

declare(strict_types=1);

use Type\Testing\Process;

/**
 * 已隔离 PostgreSQL 主库的真实同步备库；只证明本机进程间同步，不代替独立故障节点。
 * 调用方先关闭此备库，再关闭 NativeDatabase 或受控恢复 Process 持有的主库。
 */
final class PostgresSync
{
    private ?Process $replica = null;
    private ?PDO $primary = null;
    private array $environment;
    private array $report = ['state' => 'preparing', 'topology' => 'same-host-primary-and-standby'];
    private int $port;
    private string $password;

    /**
     * @param NativeDatabase|array<string,string> $database 本轮主库所有者或已提升恢复库的显式TYPE_PGSQL环境。
     * @param array<string,string> $tools NativeDatabase::tools('pgsql', ...) 的工具清单。
     */
    public function __construct(NativeDatabase|array $database, private string $directory, private array $tools, private string $name = 'iot_sync')
    {
        expect(preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $name) === 1, '同步备库名称必须明确且合法');
        $parent = realpath(dirname($directory));
        $build = realpath(dirname(__DIR__) . '/build');
        expect($parent !== false && $build !== false && ($parent === $build || str_starts_with($parent, $build . '/'))
            && !file_exists($directory) && !is_link($directory), '同步备库必须使用 build 下的新私有目录');
        expect(is_executable($tools['root'] . '/bin/pg_basebackup'), '缺少 PostgreSQL pg_basebackup 工具');
        expect(mkdir($directory, 0700), '无法创建同步备库目录');
        $this->environment = array_replace(getenv(), $database instanceof NativeDatabase ? $database->environment() : $database);
        expect(($this->environment['TYPE_PGSQL_HOST'] ?? '') === '127.0.0.1', '同步装置只可连接本机隔离主库');
        $this->password = $this->environment['TYPE_PGSQL_PASSWORD'];
        $this->primary = $this->connect(false);
        $primaryDirectory = realpath((string) $this->primary->query('SHOW data_directory')->fetchColumn());
        expect(is_string($primaryDirectory) && str_starts_with($primaryDirectory, $build . '/')
            && $this->primary->query('SELECT pg_is_in_recovery()')->fetchColumn() === false, '同步装置必须连接本轮build下已提升的主库');
        $settings = $this->primary->query("SELECT current_setting('fsync') AS fsync, current_setting('full_page_writes') AS full_page_writes, current_setting('wal_level') AS wal_level")->fetch(PDO::FETCH_ASSOC);
        expect($settings['fsync'] === 'on' && $settings['full_page_writes'] === 'on' && in_array($settings['wal_level'], ['replica', 'logical'], true), '主库未满足 WAL 持久化前置条件');
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        expect(is_resource($socket), '无法分配隔离备库端口');
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $passwordFile = $directory . '/pgpass';
        $pass = '127.0.0.1:' . $this->environment['TYPE_PGSQL_PORT'] . ':*:' . $this->environment['TYPE_PGSQL_USER'] . ':' . $this->password . "\n";
        expect(file_put_contents($passwordFile, $pass) === strlen($pass) && chmod($passwordFile, 0600), '无法创建隔离复制凭据');
        $this->environment['PGPASSFILE'] = $passwordFile;
        try {
            nativeDatabaseCommand(
                [$tools['root'] . '/bin/pg_basebackup', '-h', '127.0.0.1', '-p', $this->environment['TYPE_PGSQL_PORT'],
                '-U', $this->environment['TYPE_PGSQL_USER'], '-D', $directory . '/data', '-R', '-X', 'stream', '-c', 'fast', '--no-password'],
                $this->environment,
                [$this->password],
                $directory . '/basebackup.log',
                60
            );
            $this->start();
            $this->primary->exec("ALTER SYSTEM SET synchronous_standby_names = 'FIRST 1 (" . $this->name . ")'");
            $this->primary->query('SELECT pg_reload_conf()')->fetchColumn();
            $this->primary->exec("SET synchronous_commit = 'remote_apply'");
            $this->waitSynchronous();
            $this->report['state'] = 'ready';
            $this->report['settings'] = $settings;
            $this->report['replication'] = $this->replication();
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** 新会话显式要求 remote_apply 和有界语句等待；调用方自行结束事务。 */
    public function connection(): PDO
    {
        $connection = $this->connect(false);
        $connection->exec("SET synchronous_commit = 'remote_apply'");
        $connection->exec('SET statement_timeout = 3000');
        return $connection;
    }

    /** 在真实只读备库查询已重放记录；不将记录可见性当作未知主库提交的成功证明。 */
    public function standby(): PDO
    {
        return $this->connect(true);
    }

    /** 模拟备库进程退出，保留主库严格同步配置，不自动降级为异步。 */
    public function stopStandby(): void
    {
        if ($this->replica === null) {
            return;
        }
        $result = $this->replica->stop(10);
        file_put_contents($this->directory . '/standby.log', str_replace($this->password, '<REDACTED>', $result->stdout . $result->stderr));
        expect($result->successful(), '隔离同步备库没有正常退出');
        $this->replica = null;
        $this->report['standby_stopped'] = true;
    }

    /** 保留数据重新启动原备库，等待其重新成为同步接收方。 */
    public function restartStandby(): void
    {
        expect($this->replica === null, '备库仍在运行');
        $this->start();
        $this->waitSynchronous();
    }

    /** @return array<string,mixed> 无密码及机器路径的复制证据。 */
    public function evidence(): array
    {
        return $this->report;
    }

    /** 有界停止本实例备库并清除自己的临时复制凭据；主库由调用方负责关闭。 */
    public function close(): void
    {
        $this->stopStandby();
        $this->primary = null;
        if (is_file($this->directory . '/pgpass')) {
            expect(unlink($this->directory . '/pgpass'), '复制测试凭据未清理');
        }
        $this->report['state'] = 'closed';
    }

    private function connect(bool $standby): PDO
    {
        $connection = new PDO(
            'pgsql:host=127.0.0.1;port=' . ($standby ? $this->port : $this->environment['TYPE_PGSQL_PORT'])
            . ';dbname=' . $this->environment['TYPE_PGSQL_DATABASE'] . ';connect_timeout=2',
            $this->environment['TYPE_PGSQL_USER'],
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $connection->exec('SET statement_timeout = 3000');
        return $connection;
    }

    private function start(): void
    {
        $information = 'host=127.0.0.1 port=' . $this->environment['TYPE_PGSQL_PORT'] . ' user=' . $this->environment['TYPE_PGSQL_USER']
            . ' application_name=' . $this->name . ' passfile=' . "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $this->directory . '/pgpass') . "'";
        $this->replica = new Process([$this->tools['postgres'], '-D', $this->directory . '/data', '-h', '127.0.0.1', '-p', (string) $this->port,
            '-k', '', '-c', 'primary_conninfo=' . $information, '-c', 'hot_standby=on'], $this->directory, $this->environment, 16777216);
        $this->report['standby_stopped'] = false;
    }

    private function replication(): array
    {
        return $this->primary->query("SELECT application_name, state, sync_state, sent_lsn::text, write_lsn::text, flush_lsn::text, replay_lsn::text FROM pg_stat_replication WHERE application_name = '" . $this->name . "'")->fetchAll(PDO::FETCH_ASSOC);
    }

    private function waitSynchronous(): void
    {
        $until = microtime(true) + 15;
        do {
            expect($this->replica !== null && $this->replica->running(), '隔离同步备库启动失败');
            $rows = $this->replication();
            if (count($rows) === 1 && $rows[0]['state'] === 'streaming' && $rows[0]['sync_state'] === 'sync') {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $until);
        throw new RuntimeException('备库未在预算内成为同步接收方');
    }
}
