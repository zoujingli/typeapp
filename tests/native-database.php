<?php

declare(strict_types=1);

use Type\Build\BuildLock;
use Type\Build\BuildPlatform;
use Type\Runtime\Deadline;
use Type\Testing\Process;

/**
 * 执行明确测试命令，完整捕获双输出并脱敏保存；不经shell。
 *
 * @param list<string> $command 原生程序及参数。
 * @param array<string,string> $environment 调用者显式提供的环境。
 * @param list<string> $secrets 本轮输出中需要隐藏的测试凭据。
 * @param Closure(): void|null $observe 等待期间排空其他受控进程输出；不参与应用协议。
 * @throws RuntimeException 命令、输出预算或日志保存失败。
 */
function nativeDatabaseCommand(array $command, array $environment, array $secrets, string $log, int $seconds = 90, ?Closure $observe = null): string
{
    $process = new Process($command, dirname(__DIR__), $environment, 16777216);
    try {
        if ($observe !== null) {
            $deadline = new Deadline($seconds);
            while ($process->running() && !$deadline->expired()) {
                $observe();
                if (strlen($process->stdout()) + strlen($process->stderr()) >= 16777216) {
                    break;
                }
                usleep(10000);
            }
        }
        $result = $process->wait($observe === null ? $seconds : 0);
    } finally {
        $process->stop();
    }
    $output = str_replace($secrets, '<REDACTED>', $result->stdout . $result->stderr);
    expect(file_put_contents($log, $output) === strlen($output) && chmod($log, 0600), '无法保存原生数据库命令的私有脱敏日志');
    expect($result->successful(), '原生数据库命令失败（exit=' . $result->exitCode . ', timeout=' . (int) $result->timedOut . '），日志：' . $log);
    return $result->stdout;
}

/** 本机测试数据库的工具、数据根、认证、进程身份与退出由单一所有者维护；调用方须在finally中close。 */
final class NativeDatabase
{
    private ?Process $process = null;
    private ?string $socketDirectory = null;
    private array $environment;
    private array $evidence;
    private string $password;
    private bool $closed = false;

    /**
     * 在分配测试目录前验证原生工具；不下载、安装、运行程序或连接数据库。
     *
     * @return array<string,string> 经解析的root及本驱动明确需要的程序路径。
     * @throws RuntimeException 驱动、目录或原生文件无效。
     */
    public static function tools(string $driver, string $directory): array
    {
        expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && in_array($driver, ['mysql', 'pgsql'], true), '原生数据库工具要求Linux/macOS和明确驱动');
        $root = realpath($directory);
        expect($root !== false && is_dir($root), '数据库工具根目录不存在');
        $tools = ['root' => $root];
        $names = $driver === 'mysql' ? ['mysqld', 'mysql', 'mysqldump'] : ['postgres', 'initdb', 'pg_dump', 'pg_restore'];
        foreach ($names as $name) {
            $binary = realpath($root . '/bin/' . $name);
            expect($binary !== false && is_executable($binary) && BuildPlatform::format($binary) === (PHP_OS_FAMILY === 'Darwin' ? 'Mach-O' : 'ELF'), '需要原生数据库工具，不能使用容器、脚本或其他平台二进制：' . $name);
            $tools[$name] = $binary;
        }
        return $tools;
    }

    /**
     * 创建build下不存在的私有目录及独立实例；SQLite仅提供无服务器的测试环境。
     *
     * @param array<string,string> $tools tools()返回的本驱动工具，SQLite传空数组。
     * @param array{ca: string, certificate: string, key: string}|array{} $tls 本轮证书文件；仅用于本实例，空数组保持非TLS测试。
     * @param array<string,string> $postgresOptions 仅允许本轮归档/WAL汇总启动参数，不修改系统级配置。
     * @throws RuntimeException 环境、目录、初始化或身份检查失败；本轮资源仍会清理。
     */
    public function __construct(private string $directory, private string $driver, array $tools = [], private array $tls = [], private array $postgresOptions = [])
    {
        $root = BuildPlatform::resolve(dirname(__DIR__));
        BuildLock::path($directory);
        $parent = realpath(dirname($directory));
        expect(in_array(PHP_OS_FAMILY, ['Darwin', 'Linux'], true) && posix_geteuid() > 0 && in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)
            && $parent !== false && ($parent === $root . '/build' || str_starts_with($parent, $root . '/build/'))
            && !file_exists($directory) && !is_link($directory), '数据库测试需要非root Linux/macOS及build下的新私有目录');
        if ($driver !== 'sqlite') {
            expect(isset($tools['root']) && $tools === self::tools($driver, $tools['root']), '数据库工具清单不匹配');
        }
        foreach ($postgresOptions as $option => $value) {
            expect($driver === 'pgsql' && in_array($option, ['archive_mode', 'archive_command', 'archive_timeout', 'summarize_wal', 'wal_summary_keep_time'], true)
                && is_string($value) && $value !== '' && strlen($value) <= 8192 && !str_contains($value, "\0"), '隔离PostgreSQL归档选项无效');
        }
        if ($tls !== []) {
            expect($driver !== 'sqlite' && count($tls) === 3 && isset($tls['ca'], $tls['certificate'], $tls['key']), 'TLS配置必须明确提供CA、服务器证书及私钥');
            foreach ($tls as $kind => $file) {
                $resolved = realpath($file);
                expect($resolved !== false && is_file($resolved) && is_readable($resolved), 'TLS测试文件不存在或不可读');
                $this->tls[$kind] = $resolved;
            }
            expect((fileperms($this->tls['key']) & 0077) === 0, '服务器TLS私钥不能由其他用户读取');
        }
        expect(mkdir($directory, 0700), '无法创建本轮数据库工作目录');
        $this->password = bin2hex(random_bytes(24));
        $this->environment = ['PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'LC_ALL' => 'C', 'LANG' => 'C', 'MYSQL_TEST_LOGIN_FILE' => $directory . '/unused-login.cnf'];
        $this->evidence = ['state' => 'preparing', 'driver' => $driver, 'execution' => 'native', 'tools' => [], 'owned-server-stopped' => $driver === 'sqlite' ? null : false];
        try {
            if ($driver !== 'sqlite') {
                $this->start($tools);
            }
            $this->evidence['state'] = 'ready';
        } catch (Throwable $failure) {
            $this->evidence['state'] = 'failed';
            try {
                $this->close();
            } catch (Throwable $cleanup) {
                throw new RuntimeException($failure->getMessage() . '；清理失败：' . $cleanup->getMessage(), 0, $failure);
            }
            throw $failure;
        }
    }

    /** @return array<string,string> 本轮连接参数及工具路径；调用者不应记录其中的测试密码。 */
    public function environment(): array
    {
        expect(!$this->closed, '已关闭的数据库测试环境不能继续使用');
        return $this->environment;
    }

    /** @return array<string,mixed> 不含密码的工具摘要、实际服务器身份和生命周期证据。 */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /** 详细日志采集时由外层等待循环调用，避免输出管道写满影响数据库耗时；预算耗尽立即失败。 */
    public function drainOutput(): void
    {
        expect(!$this->closed && $this->process !== null && $this->process->running(), '诊断数据库提前退出');
        expect(strlen($this->process->stdout()) + strlen($this->process->stderr()) < 16777216, '诊断数据库输出超过预算');
    }

    /**
     * 幂等停止自己持有的前台进程，移除初始化秘密与空socket目录，保留数据库及日志。
     *
     * @throws RuntimeException 停止、日志或清理失败；失败状态不得计为验收通过。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $failures = [];
        if ($this->process !== null) {
            try {
                $stopped = $this->process->stop(20);
                $log = str_replace($this->password, '<REDACTED>', $stopped->stdout . $stopped->stderr);
                expect(file_put_contents($this->directory . '/server.log', $log) === strlen($log) && chmod($this->directory . '/server.log', 0600), '无法保存本轮服务器脱敏日志');
                $this->evidence['owned-server-stopped'] = $stopped->successful();
                expect($stopped->successful(), '本轮数据库没有正常退出，见server.log');
                $this->process = null;
            } catch (Throwable $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        foreach (['initialize.sql', 'password'] as $file) {
            if (is_file($this->directory . '/' . $file) && !unlink($this->directory . '/' . $file)) {
                $failures[] = '初始化凭据清理失败';
            }
        }
        if ($this->socketDirectory !== null && is_dir($this->socketDirectory) && !rmdir($this->socketDirectory)) {
            $failures[] = '短socket目录仍非空，保留现场';
        }
        if ($failures !== []) {
            $this->evidence['state'] = 'failed';
        } elseif ($this->evidence['state'] !== 'failed') {
            $this->evidence['state'] = 'closed';
        }
        $json = json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        expect(file_put_contents($this->directory . '/database.json', $json) === strlen($json) && chmod($this->directory . '/database.json', 0600), '无法保存数据库生命周期证据');
        expect($failures === [], implode('；', $failures));
        $this->closed = true;
    }

    /** 初始化与监听仅使用本轮目录；已有全局配置、服务或数据库不参与。 */
    private function start(array $tools): void
    {
        $this->environment['PATH'] = $tools['root'] . '/bin:' . $this->environment['PATH'];
        foreach ($tools as $name => $binary) {
            if ($name !== 'root') {
                $this->evidence['tools'][$name] = ['binary' => $binary, 'sha256' => hash_file('sha256', $binary)];
            }
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $message);
        expect(is_resource($socket), '无法选择本轮数据库回环端口');
        $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $data = $this->directory . '/data';
        if ($this->driver === 'mysql') {
            $short = trim($this->command(['/usr/bin/mktemp', '-d', '/tmp/type-db-XXXXXXXX'], 'socket-directory.log', 5));
            expect(preg_match('#^/tmp/type-db-[A-Za-z0-9]{8}$#D', $short) === 1 && is_dir($short)
                && fileowner($short) === posix_geteuid() && (fileperms($short) & 0077) === 0, '短socket目录归属或权限不符');
            $this->socketDirectory = $short;
            $this->command([$tools['mysqld'], '--no-defaults', '--initialize-insecure', '--basedir=' . $tools['root'], '--datadir=' . $data], 'initialize.log');
            $setup = "ALTER USER 'root'@'localhost' IDENTIFIED BY '" . $this->password . "';\nCREATE DATABASE type_app_test;\n";
            expect(file_put_contents($this->directory . '/initialize.sql', $setup) === strlen($setup) && chmod($this->directory . '/initialize.sql', 0600), '无法写入私有初始化声明');
            $command = [$tools['mysqld'], '--no-defaults', '--basedir=' . $tools['root'], '--datadir=' . $data,
                '--bind-address=127.0.0.1', '--port=' . $port, '--socket=' . $short . '/mysql.sock', '--pid-file=' . $data . '/mysql.pid',
                '--mysqlx=OFF', '--skip-log-bin', '--innodb-buffer-pool-size=64M', '--innodb-redo-log-capacity=64M', '--init-file=' . $this->directory . '/initialize.sql'];
            if ($this->tls !== []) {
                $command = [...$command, '--ssl-ca=' . $this->tls['ca'], '--ssl-cert=' . $this->tls['certificate'],
                    '--ssl-key=' . $this->tls['key'], '--require-secure-transport=ON'];
            }
        } else {
            expect(file_put_contents($this->directory . '/password', $this->password . "\n") === strlen($this->password) + 1 && chmod($this->directory . '/password', 0600), '无法写入私有密码文件');
            $this->command([$tools['initdb'], '-D', $data, '-U', 'type_app', '--auth-local=reject', '--auth-host=scram-sha-256',
                '--pwfile=' . $this->directory . '/password', '--locale=C', '--encoding=UTF8'], 'initialize.log');
            $command = [$tools['postgres'], '-D', $data, '-h', '127.0.0.1', '-p', $port, '-k', ''];
            foreach ($this->postgresOptions as $option => $value) {
                $command = [...$command, '-c', $option . '=' . $value];
            }
            if ($this->tls !== []) {
                $command = [...$command, '-c', 'ssl=on', '-c', 'ssl_ca_file=' . $this->tls['ca'],
                    '-c', 'ssl_cert_file=' . $this->tls['certificate'], '-c', 'ssl_key_file=' . $this->tls['key']];
            }
        }
        $this->process = new Process($command, $this->directory, $this->environment, 16777216);
        $this->evidence['server'] = $this->ready($data, $port);
        foreach (['initialize.sql', 'password'] as $file) {
            if (is_file($this->directory . '/' . $file)) {
                expect(unlink($this->directory . '/' . $file), '无法回收初始化凭据');
            }
        }
        $prefix = 'TYPE_' . strtoupper($this->driver) . '_';
        foreach (['HOST' => '127.0.0.1', 'PORT' => $port, 'DATABASE' => 'type_app_test', 'USER' => $this->driver === 'mysql' ? 'root' : 'type_app', 'PASSWORD' => $this->password] as $key => $value) {
            $this->environment[$prefix . $key] = $value;
        }
    }

    /** 已连接后的SQL不进入重试，以免重复执行提交结果未知的DDL。 */
    private function ready(string $data, string $port): array
    {
        $mysql = $this->driver === 'mysql';
        $dsn = ($mysql ? 'mysql:' : 'pgsql:') . 'host=127.0.0.1;port=' . $port . ';dbname=' . ($mysql ? 'type_app_test' : 'postgres');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 1];
        if ($this->tls !== []) {
            if ($mysql) {
                $options[\Pdo\Mysql::ATTR_SSL_CA] = $this->tls['ca'];
                $options[\Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = true;
            } else {
                $dsn .= ';sslmode=verify-full;sslrootcert=' . $this->tls['ca'];
            }
            $this->evidence['tls'] = ['ca-sha256' => hash_file('sha256', $this->tls['ca']),
                'certificate-sha256' => hash_file('sha256', $this->tls['certificate'])];
        }
        $deadline = microtime(true) + 60;
        do {
            expect($this->process->running(), '本轮原生数据库在就绪前退出');
            try {
                $connection = new PDO($dsn, $mysql ? 'root' : 'type_app', $this->password, $options);
            } catch (PDOException) {
                usleep(100000);
                continue;
            }
            $actual = $connection->query($mysql ? 'SELECT @@datadir' : 'SHOW data_directory')->fetchColumn();
            expect(is_string($actual) && realpath($actual) === $data, '连接并非本轮数据库数据根');
            $pidFile = $data . ($mysql ? '/mysql.pid' : '/postmaster.pid');
            expect(is_file($pidFile), '数据库缺少进程身份记录');
            $pid = strtok((string) file_get_contents($pidFile), "\n");
            expect(is_string($pid) && ctype_digit($pid) && (int) $pid > 1, '数据库PID无效');
            $parent = trim($this->command(['/bin/ps', '-p', $pid, '-o', 'ppid='], 'parent-pid.log', 5));
            expect($parent === (string) getmypid(), '数据库不是本控制器直接持有的前台子进程');
            $version = (string) $connection->getAttribute(PDO::ATTR_SERVER_VERSION);
            if (!$mysql) {
                $connection->exec('CREATE DATABASE type_app_test');
            }
            $connection = null;
            return ['pid' => (int) $pid, 'port' => (int) $port, 'version' => $version, 'data' => $data, 'owner-pid' => getmypid()];
        } while (microtime(true) < $deadline);
        throw new RuntimeException('本轮原生数据库未在预算内通过认证');
    }

    private function command(array $command, string $log, int $seconds = 90): string
    {
        return nativeDatabaseCommand($command, $this->environment, [$this->password], $this->directory . '/' . $log, $seconds);
    }
}
