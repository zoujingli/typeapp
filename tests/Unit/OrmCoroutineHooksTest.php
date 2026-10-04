<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Type\Orm\Database;
use Type\Orm\Mysql\MysqlDriver;
use Type\Orm\Pgsql\PgsqlDriver;
use Type\Orm\Sqlite\SqliteDriver;
use Type\Runtime\CoroutineRuntime;
use Type\Runtime\ExecutionScope;
use Type\Runtime\TaskException;

/** 真实 Swoole hook 与驱动入口的契约回归；每项隔离进程级 handler，不连接外部数据库。 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OrmCoroutineHooksTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('swoole') || !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('需要实际 Swoole 和 PDO SQLite 验证协程入口');
        }
        \Swoole\Runtime::enableCoroutine(0);
    }

    /** 同步维护连接不需要开启协程 hook，也不被未使用的数据源能力阻塞。 */
    public function testSynchronousConnectionDoesNotRequireCoroutineHooks(): void
    {
        $pdo = (new SqliteDriver(':memory:'))->connect();
        self::assertSame('sqlite', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        self::assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
        self::assertSame(0, \Swoole\Runtime::getHookFlags());
    }

    /** 缺 hook 必须先于真实连接失败，不能让物理连接或池额度残留。 */
    public function testSelectedDriversRejectMissingStartupHooksBeforeConnecting(): void
    {
        $drivers = [[new SqliteDriver(':memory:'), defined('SWOOLE_HOOK_PDO_SQLITE')]];
        if (extension_loaded('pdo_mysql')) {
            $drivers[] = [new MysqlDriver('127.0.0.1', 1, 'unreachable', 'fixture', ''),
                extension_loaded('mysqlnd') && defined('Pdo\\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')];
        }
        if (extension_loaded('pdo_pgsql')) {
            $drivers[] = [new PgsqlDriver('127.0.0.1', 1, 'unreachable', 'fixture', ''), defined('SWOOLE_HOOK_PDO_PGSQL')];
        }
        CoroutineRuntime::run(static function () use ($drivers): void {
            foreach ($drivers as [$driver, $available]) {
                $database = new Database($driver, 1, 0);
                $scope = new ExecutionScope();
                try {
                    $database->connect($scope);
                    self::fail('协程连接接受了未启用的 PDO hook');
                } catch (TaskException $error) {
                    self::assertSame($available ? 'swoole_hook_startup_required' : 'swoole_pdo_hook_unavailable', $error->errorCode());
                    self::assertSame(0, $database->statistics()['created']);
                    self::assertSame(0, $database->statistics()['leased']);
                    self::assertSame(0, \Swoole\Runtime::getHookFlags());
                } finally {
                    $scope->close();
                    $database->close();
                }
            }
        });
    }

    /** 单个数据库 profile 仅要求自身能力，未启用其他驱动 hook 不影响真实 SQL。 */
    public function testSelectedSqliteHookDoesNotRequireOtherDatabaseHooks(): void
    {
        if (!defined('SWOOLE_HOOK_PDO_SQLITE')) {
            self::markTestSkipped('当前 Swoole 没有编译 SQLite hook');
        }
        $selected = (int) constant('SWOOLE_HOOK_PDO_SQLITE');
        \Swoole\Runtime::enableCoroutine($selected);
        CoroutineRuntime::run(static function () use ($selected): void {
            $pdo = (new SqliteDriver(':memory:'))->connect();
            $pdo->exec('CREATE TABLE hook_records (id INTEGER PRIMARY KEY, title TEXT NOT NULL)');
            $pdo->exec("INSERT INTO hook_records (title) VALUES ('selected profile')");
            self::assertSame('selected profile', $pdo->query('SELECT title FROM hook_records')->fetchColumn());
            self::assertSame($selected, \Swoole\Runtime::getHookFlags());
        });
    }

    /** 标准启动入口启用本机支持的能力；驱动检查读取配置，不在业务中改 handler。 */
    public function testStartupEnablesAvailableDriverHooksWithoutDriverMutation(): void
    {
        CoroutineRuntime::enableIo();
        $flags = \Swoole\Runtime::getHookFlags();
        CoroutineRuntime::run(static function () use ($flags): void {
            foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
                if (!extension_loaded('pdo_' . $driver)) {
                    continue;
                }
                if ($driver !== 'mysql' && !defined('SWOOLE_HOOK_PDO_' . strtoupper($driver))) {
                    continue;
                }
                CoroutineRuntime::assertPdoHooks($driver);
                self::assertSame($flags, \Swoole\Runtime::getHookFlags());
            }
        });
    }

    /** 原生线程只同步本地选项；缺失、替换或运行中的启动消息不能改变 hook 配置。 */
    public function testNativeThreadInheritsOnlyItsValidatedStartupMessage(): void
    {
        if (!class_exists(\Swoole\Thread::class, false) || !defined('SWOOLE_HOOK_PDO_SQLITE')) {
            self::markTestSkipped('需要真实 Swoole Thread 和 SQLite hook');
        }
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/thread-hooks-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $script = $directory . '/thread.php';
        file_put_contents($script, <<<'PHP'
<?php
declare(strict_types=1);
$arguments = Swoole\Thread::getArguments();
require $arguments[1] . '/vendor/autoload.php';
$flags = Swoole\Runtime::getHookFlags();
try {
    $message = $arguments[3] ? '["probe","replaced",0]' : $arguments[0];
    $data = Type\Runtime\CoroutineRuntime::enterThread($message);
    $inherited = Swoole\Runtime::getHookFlags();
    Type\Runtime\CoroutineRuntime::enableIo();
    $value = Type\Runtime\CoroutineRuntime::run(static function () use ($message): array {
        $pdo = (new Type\Orm\Sqlite\SqliteDriver(':memory:'))->connect();
        $pdo->exec('CREATE TABLE thread_records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO thread_records VALUES (1, 'thread sqlite')");
        $rejected = '';
        try {
            Type\Runtime\CoroutineRuntime::enterThread($message);
        } catch (Type\Runtime\TaskException $error) {
            $rejected = $error->errorCode();
        }
        return [$pdo->query('SELECT value FROM thread_records')->fetchColumn(), $rejected];
    });
    $result = ['entry' => $data, 'value' => $value, 'inherited' => $inherited, 'flags' => Swoole\Runtime::getHookFlags()];
} catch (Type\Runtime\TaskException $error) {
    $result = ['error' => $error->errorCode(), 'initial' => $flags, 'flags' => Swoole\Runtime::getHookFlags()];
}
file_put_contents($arguments[2], json_encode($result, JSON_THROW_ON_ERROR));
PHP);
        try {
            CoroutineRuntime::enableIo();
            $flags = \Swoole\Runtime::getHookFlags();
            $cases = [
                [json_encode(['probe', 'payload', $flags], JSON_THROW_ON_ERROR), false, null],
                ['["probe","payload"]', false, 'compiled_thread_message_invalid'],
                ['["probe","payload",0]', false, 'swoole_hook_startup_required'],
                ['["probe","payload","flags"]', false, 'compiled_thread_message_invalid'],
                [json_encode(['probe', 'payload', $flags], JSON_THROW_ON_ERROR), true, 'compiled_thread_message_invalid'],
                [json_encode(['probe', 'payload', $flags], JSON_THROW_ON_ERROR), false, null],
            ];
            foreach ($cases as $index => [$message, $replace, $expected]) {
                $output = $directory . '/' . $index . '.json';
                $thread = new \Swoole\Thread($script, $message, $root, $output, $replace);
                self::assertTrue($thread->join());
                self::assertSame(0, $thread->getExitStatus());
                $result = json_decode((string) file_get_contents($output), true, 512, JSON_THROW_ON_ERROR);
                if ($expected !== null) {
                    self::assertSame($expected, $result['error']);
                    self::assertSame($result['initial'], $result['flags']);
                } else {
                    self::assertSame(['probe', 'payload'], $result['entry']);
                    self::assertSame(['thread sqlite', 'compiled_thread_message_invalid'], $result['value']);
                    self::assertSame($flags, $result['inherited']);
                    self::assertSame($flags, $result['flags']);
                }
                self::assertSame($flags, \Swoole\Runtime::getHookFlags());
            }
            self::assertSame(1, \Swoole\Thread::activeCount());
            try {
                CoroutineRuntime::enterThread(json_encode(['probe', 'payload', $flags], JSON_THROW_ON_ERROR));
                self::fail('主线程接受了业务线程启动消息');
            } catch (TaskException $error) {
                self::assertSame('compiled_thread_message_invalid', $error->errorCode());
            }
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
