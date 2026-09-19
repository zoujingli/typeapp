<?php

declare(strict_types=1);

use Type\Testing\Process;

/** 真实三节点Patroni/etcd装置；同机、watchdog关闭，仅作流程证据，不能替代独立故障节点。 */
final class PostgresHa
{
    private array $processes = [];
    private array $nodes = [];
    private array $nodeEnvironments = [];
    private array $report = ['state' => 'preparing', 'topology' => 'same-host-three-patroni-three-etcd', 'watchdog' => 'off', 'hard_fencing_verified' => false, 'faults' => []];
    private array $environment;
    private string $password;
    private string $patroni;
    private string $haproxy;
    private int $writerPort;
    private bool $closed = false;

    /** @param array<string,string> $tools 已由NativeDatabase验证的PostgreSQL工具清单。 */
    public function __construct(private string $directory, private array $tools)
    {
        $parent = realpath(dirname($directory));
        $build = realpath(dirname(__DIR__) . '/build');
        expect(posix_geteuid() > 0 && $parent !== false && $build !== false && ($parent === $build || str_starts_with($parent, $build . '/'))
            && !file_exists($directory) && !is_link($directory), 'HA装置需要非root用户及build下的新私有目录');
        $this->patroni = $this->tool('TYPE_PATRONI_BINARY');
        $this->haproxy = $this->tool('TYPE_HAPROXY_BINARY');
        $etcd = $this->tool('TYPE_ETCD_BINARY');
        expect(mkdir($directory, 0700), '无法创建HA私有目录');
        $this->password = bin2hex(random_bytes(24));
        $this->environment = getenv();
        // 本轮配置完整显式传递，不让用户的全局Patroni环境覆盖测试边界。
        foreach (array_keys($this->environment) as $key) {
            if (str_starts_with($key, 'PATRONI_') || str_starts_with($key, 'IOT_HA_')) {
                unset($this->environment[$key]);
            }
        }
        try {
            $this->certificate();
            $this->report['tools'] = [
                'postgres' => trim($this->run([$tools['postgres'], '--version'], 'postgres-version.log')),
                'patroni' => trim($this->run([$this->patroni, '--version'], 'patroni-version.log')),
                'haproxy' => strtok($this->run([$this->haproxy, '-v'], 'haproxy-version.log'), "\n"),
                'etcd' => trim($this->run([$etcd, '--version'], 'etcd-version.log')),
            ];
            $cluster = [];
            foreach (['iot_a', 'iot_b', 'iot_c'] as $name) {
                $this->nodes[$name] = ['postgres' => $this->port(), 'api' => $this->port(), 'client' => $this->port(), 'http' => $this->port(), 'peer' => $this->port(), 'proxy' => $this->port()];
                $cluster[] = $name . '=https://127.0.0.1:' . $this->nodes[$name]['peer'];
                expect(mkdir($directory . '/' . $name, 0700), '无法创建HA节点目录');
            }
            foreach ($this->nodes as $name => $ports) {
                $this->processes['etcd-' . $name] = new Process([$etcd, '--name', $name, '--data-dir', $directory . '/' . $name . '/etcd',
                    '--listen-client-urls', 'https://127.0.0.1:' . $ports['client'], '--advertise-client-urls', 'https://127.0.0.1:' . $ports['client'],
                    '--listen-client-http-urls', 'https://127.0.0.1:' . $ports['http'],
                    '--listen-peer-urls', 'https://127.0.0.1:' . $ports['peer'], '--initial-advertise-peer-urls', 'https://127.0.0.1:' . $ports['peer'],
                    '--initial-cluster', implode(',', $cluster), '--initial-cluster-token', basename($directory),
                    '--cert-file', $directory . '/tls/certificate.pem', '--key-file', $directory . '/tls/private.pem', '--trusted-ca-file', $directory . '/tls/certificate.pem', '--client-cert-auth',
                    '--peer-cert-file', $directory . '/tls/certificate.pem', '--peer-key-file', $directory . '/tls/private.pem', '--peer-trusted-ca-file', $directory . '/tls/certificate.pem', '--peer-client-cert-auth', '--log-level', 'warn'], $directory, $this->environment, 16777216);
            }
            $this->wait('etcd多数派未就绪', function (): bool {
                $ready = 0;
                foreach ($this->nodes as $ports) {
                    $ready += $this->http($ports['http'], '/health')['status'] === 200 ? 1 : 0;
                }
                return $ready === 3;
            });
            $template = json_decode(file_get_contents(dirname(__DIR__) . '/docs/deployment/iot-ha/patroni.json'), true, 64, JSON_THROW_ON_ERROR);
            foreach ($this->nodes as $name => $ports) {
                $proxy = "defaults\n    mode tcp\n    timeout connect 1s\n    timeout client 35s\n    timeout server 35s\nlisten dcs\n    bind 127.0.0.1:" . $ports['proxy'] . "\n";
                foreach ($this->nodes as $peer => $peerPorts) {
                    $proxy .= '    server ' . $peer . ' 127.0.0.1:' . $peerPorts['http'] . ' check inter 1s check-ssl verify required ca-file ' . $directory . '/tls/certificate.pem crt ' . $directory . "/tls/client.pem\n";
                }
                file_put_contents($directory . '/' . $name . '/dcs.cfg', $proxy);
                $this->startProxy($name);
                $configuration = $template;
                $configuration['scope'] = basename($directory);
                $configuration['name'] = $name;
                $configuration['restapi'] = ['listen' => '127.0.0.1:' . $ports['api'], 'connect_address' => '127.0.0.1:' . $ports['api'],
                    'certfile' => $directory . '/tls/certificate.pem', 'keyfile' => $directory . '/tls/private.pem', 'cafile' => $directory . '/tls/certificate.pem', 'verify_client' => 'required'];
                $configuration['ctl'] = ['insecure' => false, 'cacert' => $directory . '/tls/certificate.pem', 'certfile' => $directory . '/tls/certificate.pem', 'keyfile' => $directory . '/tls/private.pem'];
                // 每个Patroni仅通过自己持有的TCP转发接触DCS，以便注入真实连接中断；禁止发现绕过入口。
                $configuration['etcd3'] = ['proxy' => 'https://127.0.0.1:' . $ports['proxy'], 'cacert' => $directory . '/tls/certificate.pem',
                    'cert' => $directory . '/tls/certificate.pem', 'key' => $directory . '/tls/private.pem'];
                $configuration['postgresql']['listen'] = '127.0.0.1:' . $ports['postgres'];
                $configuration['postgresql']['connect_address'] = '127.0.0.1:' . $ports['postgres'];
                $configuration['postgresql']['data_dir'] = $directory . '/' . $name . '/data';
                $configuration['postgresql']['bin_dir'] = $tools['root'] . '/bin';
                $configuration['postgresql']['pgpass'] = $directory . '/' . $name . '/pgpass';
                foreach (['superuser', 'replication'] as $role) {
                    $configuration['postgresql']['authentication'][$role]['password'] = $this->password;
                    $configuration['postgresql']['authentication'][$role]['sslmode'] = 'verify-full';
                    $configuration['postgresql']['authentication'][$role]['sslrootcert'] = $directory . '/tls/certificate.pem';
                }
                $configuration['postgresql']['parameters']['ssl_cert_file'] = $directory . '/tls/certificate.pem';
                $configuration['postgresql']['parameters']['ssl_key_file'] = $directory . '/tls/private.pem';
                $configuration['postgresql']['parameters']['ssl_ca_file'] = $directory . '/tls/certificate.pem';
                $configuration['postgresql']['parameters']['shared_buffers'] = '32MB';
                $configuration['watchdog'] = ['mode' => 'off'];
                $this->nodeEnvironments[$name] = $this->environment + ['PATRONI_CONFIGURATION' => json_encode($configuration, JSON_THROW_ON_ERROR)];
                $validation = (new Process([$this->patroni, '--validate-config', '--ignore-listen-port'], $directory, $this->nodeEnvironments[$name]))->wait(15);
                $expectedValidation = 'restapi.connect_address 127.0.0.1:' . $ports['api'] . ' didn\'t pass validation: must not contain "127.0.0.1", "0.0.0.0", "*", "::1", "localhost"' . "\n"
                    . 'postgresql.connect_address 127.0.0.1:' . $ports['postgres'] . ' didn\'t pass validation: must not contain "127.0.0.1", "0.0.0.0", "*", "::1", "localhost"' . "\n";
                file_put_contents($directory . '/' . $name . '/validate.log', $validation->stdout . $validation->stderr);
                expect($validation->exitCode === 1 && trim($validation->stdout . $validation->stderr) === trim($expectedValidation), '本机Patroni配置存在回环公告地址之外的校验错误');
                $this->report['configuration_validation'] = 'loopback-advertisement-is-an-explicit-local-only-exception';
                $this->startNode($name);
            }
            $this->ready();
            $this->writerPort = $this->port();
            $writerEnvironment = $this->environment + ['IOT_HA_LISTEN' => '127.0.0.1:' . $this->writerPort, 'IOT_HA_HEALTH_HOST' => 'localhost',
                'IOT_HA_CA' => $directory . '/tls/certificate.pem', 'IOT_HA_CLIENT_PEM' => $directory . '/tls/client.pem'];
            foreach ($this->nodes as $name => $ports) {
                $suffix = strtoupper(substr($name, -1));
                $writerEnvironment['IOT_HA_DB_' . $suffix] = '127.0.0.1:' . $ports['postgres'];
                $writerEnvironment['IOT_HA_API_PORT_' . $suffix] = (string) $ports['api'];
                $writerEnvironment['IOT_HA_HOST_' . $suffix] = 'localhost';
            }
            $writerConfig = dirname(__DIR__) . '/docs/deployment/iot-ha/haproxy.cfg';
            $this->run([$this->haproxy, '-c', '-f', $writerConfig], 'writer-validate.log', $writerEnvironment);
            $this->processes['writer'] = new Process([$this->haproxy, '-db', '-f', $writerConfig], $directory, $writerEnvironment, 16777216);
            $this->wait('写入口未就绪', fn (): bool => $this->connection('postgres')->query('SELECT NOT pg_is_in_recovery()')->fetchColumn() === true);
            $this->connection('postgres')->exec('CREATE DATABASE iot_ha');
            $this->report['state'] = 'ready';
            $this->report['initial'] = $this->snapshot();
        } catch (Throwable $failure) {
            $this->report['state'] = 'failed';
            $this->close();
            throw $failure;
        }
    }

    /** @return array<string,string> 本轮写入口、TLS与测试凭据；不得将返回值写入证据。 */
    public function environment(): array
    {
        return ['TYPE_PGSQL_HOST' => '127.0.0.1', 'TYPE_PGSQL_PORT' => (string) $this->writerPort, 'TYPE_PGSQL_USER' => 'iot_cluster_admin',
            'TYPE_PGSQL_PASSWORD' => $this->password, 'TYPE_PGSQL_DATABASE' => 'iot_ha', 'TYPE_PGSQL_CA' => $this->directory . '/tls/certificate.pem',
            'TYPE_PGSQL_STANDBY_NAMES' => 'iot_a,iot_b,iot_c'];
    }

    /** 新建有界TLS连接；node仅用于对本轮旧主作直接只读/拒写核验。 */
    public function connection(string $database = 'iot_ha', ?string $node = null): PDO
    {
        $port = $node === null ? $this->writerPort : $this->nodes[$node]['postgres'];
        $connection = new PDO(
            'pgsql:host=127.0.0.1;port=' . $port . ';dbname=' . $database . ';connect_timeout=2;sslmode=verify-full;sslrootcert=' . $this->directory . '/tls/certificate.pem',
            'iot_cluster_admin',
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $connection->exec('SET statement_timeout = 4000');
        return $connection;
    }

    /** 从各节点真实/primary端点获取持有租约的主库；没有主库时抛出异常。 */
    public function leader(): string
    {
        foreach ($this->nodes as $name => $ports) {
            if (isset($this->processes['patroni-' . $name]) && $this->http($ports['api'], '/primary')['status'] === 200) {
                return $name;
            }
        }
        throw new RuntimeException('当前没有持有租约的主库');
    }

    /** 切断本轮主库的DCS TCP入口；复制网络仍通，验证Patroni主动降级。 */
    public function partitionLeader(): array
    {
        $old = $this->leader();
        $started = microtime(true);
        $this->stop('dcs-' . $old);
        $this->wait('DCS隔离后未选出新主', fn (): bool => $this->leader() !== $old, 65);
        $this->ready();
        $new = $this->leader();
        $this->wait('旧主仍可经直连写入', fn (): bool => $this->connection('postgres', $old)->query('SELECT pg_is_in_recovery()')->fetchColumn() === true, 20);
        $this->assertReadOnly($old);
        $this->startProxy($old);
        $this->wait('旧主DCS连接恢复后角色错误', fn (): bool => $this->http($this->nodes[$old]['api'], '/replica')['status'] === 200);
        $this->ready();
        $event = ['fault' => 'primary-dcs-network-partition', 'old' => $old, 'new' => $new, 'seconds' => microtime(true) - $started, 'old_primary_read_only' => true,
            'fencing' => 'cooperative-patroni-demotion-only'];
        $this->report['faults'][] = $event;
        return $event;
    }

    /** 先冻结本轮Patroni，再立即停止其确切数据目录，最后结束控制进程；不操作其他PID或宿主。 */
    public function crashLeader(): array
    {
        $old = $this->leader();
        $started = microtime(true);
        $controller = $this->processes['patroni-' . $old];
        expect($controller->running() && posix_kill($controller->pid(), SIGSTOP), '无法冻结本轮Patroni');
        try {
            $this->run([$this->tools['root'] . '/bin/pg_ctl', '-D', $this->directory . '/' . $old . '/data', '-m', 'immediate', '-w', '-t', '10', 'stop'], $old . '/crash.log');
        } finally {
            $this->stop('patroni-' . $old, 0, false);
        }
        $this->wait('主库退出后未选出新主', fn (): bool => $this->leader() !== $old, 65);
        $this->ready();
        $new = $this->leader();
        $this->startNode($old);
        $this->wait('旧主返回未加入复制', fn (): bool => $this->http($this->nodes[$old]['api'], '/replica')['status'] === 200, 65);
        $this->assertReadOnly($old);
        $this->ready();
        $event = ['fault' => 'primary-process-loss-and-return', 'old' => $old, 'new' => $new, 'seconds' => microtime(true) - $started, 'old_primary_read_only' => true];
        $this->report['faults'][] = $event;
        return $event;
    }

    /** @return list<string> 本轮正常停止的全部备库名称；不修改严格同步配置。 */
    public function stopStandbys(): array
    {
        $leader = $this->leader();
        $stopped = [];
        foreach (array_keys($this->nodes) as $name) {
            if ($name !== $leader) {
                $this->stop('patroni-' . $name);
                $stopped[] = $name;
            }
        }
        $this->wait('同步备库仍在运行', fn (): bool => (int) $this->connection()->query("SELECT count(*) FROM pg_stat_replication WHERE sync_state = 'sync'")->fetchColumn() === 0);
        return $stopped;
    }

    /** @param list<string> $names stopStandbys返回的明确节点清单。 */
    public function restartStandbys(array $names): void
    {
        foreach ($names as $name) {
            expect(isset($this->nodes[$name]) && !isset($this->processes['patroni-' . $name]), '只能恢复本轮已停止的备库');
            $this->startNode($name);
        }
        $this->ready();
        $this->report['faults'][] = ['fault' => 'all-synchronous-standbys-lost-and-restored', 'nodes' => $names];
    }

    /** @return array<string,mixed> DCS角色和实际WAL复制事实；不含凭据或本机路径。 */
    public function snapshot(): array
    {
        $leader = $this->leader();
        $connection = $this->connection('postgres', $leader);
        $cluster = json_decode((string) $this->http($this->nodes[$leader]['api'], '/cluster')['body'], true, 32, JSON_THROW_ON_ERROR);
        $members = array_map(static fn (array $member): array => array_intersect_key($member, array_flip(['name', 'role', 'state', 'timeline'])), $cluster['members']);
        return ['leader' => $leader, 'members' => $members, 'settings' => $connection->query("SELECT current_setting('synchronous_standby_names') AS names, current_setting('synchronous_commit') AS commit, current_setting('fsync') AS fsync, current_setting('full_page_writes') AS full_page_writes")->fetch(PDO::FETCH_ASSOC),
            'replication' => $connection->query('SELECT application_name, state, sync_state, flush_lsn::text, replay_lsn::text FROM pg_stat_replication ORDER BY application_name')->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @return array<string,mixed> 当前故障及生命周期证据；close后才包含最终回收结果。 */
    public function evidence(): array
    {
        return $this->report;
    }

    /** 业务验收失败必须覆盖为failed，随后正常清理不能改写为通过。 */
    public function failed(): void
    {
        $this->report['state'] = 'failed';
    }

    /** 有界停止本实例服务及其数据库子进程，删除本轮秘密，保留脱敏日志。 */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $failures = [];
        foreach (['writer', ...array_map(static fn (string $name): string => 'patroni-' . $name, array_keys($this->nodes)),
            ...array_map(static fn (string $name): string => 'dcs-' . $name, array_keys($this->nodes)),
            ...array_map(static fn (string $name): string => 'etcd-' . $name, array_keys($this->nodes))] as $name) {
            try {
                $this->stop($name);
            } catch (Throwable $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        // Patroni异常退出也可能留下数据库子进程；按本对象的新数据目录核验并收回，不能只统计控制进程。
        foreach (array_keys($this->nodes) as $name) {
            try {
                if (is_file($this->directory . '/' . $name . '/data/postmaster.pid')) {
                    $this->run([$this->tools['root'] . '/bin/pg_ctl', '-D', $this->directory . '/' . $name . '/data', '-m', 'fast', '-w', '-t', '10', 'stop'], $name . '/cleanup-postgres.log');
                }
                expect(!is_file($this->directory . '/' . $name . '/data/postmaster.pid'), 'HA数据库子进程未停止：' . $name);
            } catch (Throwable $failure) {
                $failures[] = $failure->getMessage();
            }
        }
        foreach (['tls/private.pem', 'tls/client.pem', ...array_map(static fn (string $name): string => $name . '/pgpass', array_keys($this->nodes))] as $file) {
            if (is_file($this->directory . '/' . $file) && !unlink($this->directory . '/' . $file)) {
                $failures[] = '临时凭据未清理：' . $file;
            }
        }
        $this->report['cleanup_errors'] = $failures;
        $this->report['owned_processes_stopped'] = $this->processes === [] && $failures === [];
        $this->report['state'] = $failures === [] && $this->report['state'] !== 'failed' ? 'closed' : 'failed';
        file_put_contents($this->directory . '/verification.json', json_encode($this->report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        $this->closed = true;
        expect($failures === [], implode('；', $failures));
    }

    private function ready(): void
    {
        $this->wait('Patroni严格同步未恢复', function (): bool {
            $snapshot = $this->snapshot();
            $selected = trim($snapshot['settings']['names'], '"');
            $members = array_filter($snapshot['members'], static fn (array $row): bool => $row['name'] === $selected && $row['role'] === 'sync_standby');
            return isset($this->nodes[$selected]) && count($members) === 1 && $snapshot['settings']['commit'] === 'remote_apply'
                && count(array_filter($snapshot['replication'], static fn (array $row): bool => $row['application_name'] === $selected && $row['sync_state'] === 'sync' && $row['state'] === 'streaming')) === 1;
        }, 65);
    }

    private function assertReadOnly(string $name): void
    {
        $connection = $this->connection('postgres', $name);
        expect($connection->query('SELECT pg_is_in_recovery()')->fetchColumn() === true, '旧主必须处于恢复模式');
        try {
            $connection->exec('CREATE TABLE t28_forbidden_old_primary_write (id INTEGER)');
            throw new RuntimeException('旧主接受了实际写入');
        } catch (PDOException $failure) {
            expect($failure->getCode() === '25006', '旧主写入没有按只读语义拒绝');
        }
    }

    private function wait(string $message, Closure $condition, int $seconds = 30): void
    {
        $until = microtime(true) + $seconds;
        $last = '';
        do {
            foreach ($this->processes as $name => $process) {
                expect($process->running(), 'HA进程提前退出：' . $name . ' ' . str_replace($this->password, '<REDACTED>', $process->stderr()));
            }
            try {
                if ($condition()) {
                    return;
                }
            } catch (Throwable $failure) {
                $last = $failure->getMessage();
            }
            usleep(100000);
        } while (microtime(true) < $until);
        throw new RuntimeException($message . '：' . str_replace($this->password, '<REDACTED>', $last));
    }

    private function http(int $port, string $path): array
    {
        $context = stream_context_create(['ssl' => ['cafile' => $this->directory . '/tls/certificate.pem', 'local_cert' => $this->directory . '/tls/certificate.pem',
            'local_pk' => $this->directory . '/tls/private.pem', 'verify_peer' => true, 'verify_peer_name' => true],
            'http' => ['timeout' => 1, 'ignore_errors' => true, 'header' => "Connection: close\r\n"]]);
        $body = @file_get_contents('https://127.0.0.1:' . $port . $path, false, $context);
        $headers = http_get_last_response_headers() ?? [];
        return ['status' => preg_match('#^HTTP/\S+ (\d+)#', $headers[0] ?? '', $match) === 1 ? (int) $match[1] : 0, 'body' => $body];
    }

    private function startNode(string $name): void
    {
        $this->processes['patroni-' . $name] = new Process([$this->patroni], $this->directory . '/' . $name, $this->nodeEnvironments[$name], 16777216);
    }

    private function startProxy(string $name): void
    {
        $this->processes['dcs-' . $name] = new Process([$this->haproxy, '-db', '-f', $this->directory . '/' . $name . '/dcs.cfg'], $this->directory, $this->environment, 16777216);
    }

    private function stop(string $name, int $seconds = 15, bool $graceful = true): void
    {
        if (!isset($this->processes[$name])) {
            return;
        }
        $result = $this->processes[$name]->stop($seconds);
        file_put_contents($this->directory . '/' . $name . '.log', str_replace($this->password, '<REDACTED>', $result->stdout . $result->stderr), FILE_APPEND);
        unset($this->processes[$name]);
        expect(!$graceful || !$result->timedOut, 'HA进程退出超过预算：' . $name);
    }

    private function run(array $command, string $log, ?array $environment = null): string
    {
        return nativeDatabaseCommand($command, $environment ?? $this->environment, [$this->password], $this->directory . '/' . $log, 30);
    }

    private function tool(string $key): string
    {
        $path = realpath((string) getenv($key));
        expect($path !== false && is_file($path) && is_executable($path), 'HA验收缺少明确工具：' . $key);
        return $path;
    }

    private function port(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        expect(is_resource($socket), '无法分配本轮HA端口');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        return $port;
    }

    private function certificate(): void
    {
        expect(mkdir($this->directory . '/tls', 0700), '无法创建HA测试TLS目录');
        $config = $this->directory . '/tls/openssl.cnf';
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=server\n[dn]\n[server]\nsubjectAltName=IP:127.0.0.1,DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth,clientAuth\n");
        $options = ['config' => $config, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
        $key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'localhost'], $key, $options);
        $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
        expect(openssl_x509_export($certificate, $pem) && openssl_pkey_export($key, $secret), '无法生成本轮HA测试证书');
        file_put_contents($this->directory . '/tls/certificate.pem', $pem);
        file_put_contents($this->directory . '/tls/private.pem', $secret);
        file_put_contents($this->directory . '/tls/client.pem', $pem . $secret);
        expect(chmod($this->directory . '/tls/private.pem', 0600) && chmod($this->directory . '/tls/client.pem', 0600), 'HA测试私钥权限错误');
    }
}
