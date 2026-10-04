<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Testing\Process;

require_once dirname(__DIR__) . '/support.php';

/** 覆盖测试命令哨兵的跨平台参数、输出和退出码契约，避免 shell 改写参数。 */
final class TestCommandTest extends TestCase
{
    /** 失效专项必须在读取原生产物或启动服务前失败，不能只执行基础场景后返回成功。 */
    public function testIdentitySuiteRejectsRetiredAndMixedScenariosBeforeSetup(): void
    {
        $root = dirname(__DIR__, 2);
        $cases = [
            ['iot-identity.php', ['sqlite', '--app', '--load-baseline'], '验收选项已退出或尚未接入当前身份'],
            ['iot-identity.php', ['sqlite', '--broker', '--support'], '验收选项已退出或尚未接入当前身份'],
            ['iot-identity.php', ['sqlite', '--devices', '--recovery'], '验收选项已退出或尚未接入当前身份'],
            ['iot-identity.php', ['sqlite', '--app', '--broker'], '身份验收不能混用'],
            ['iot-identity.php', ['sqlite', '--app', '--broker-observability'], 'Broker 专项需要 --broker'],
            ['iot-identity-databases.php', ['missing-mysql-tools', 'missing-pgsql-tools', '--app', '--broker-audit'], '用法：'],
        ];
        foreach ($cases as [$script, $arguments, $reason]) {
            $process = new Process([PHP_BINARY, $root . '/tests/' . $script, 'missing-test-artifact', ...$arguments], $root);
            try {
                $result = $process->wait(10);
                self::assertFalse($result->successful());
                self::assertStringContainsString($reason, $result->stdout . $result->stderr);
                self::assertStringNotContainsString('原生身份验收需要原生产物', $result->stdout . $result->stderr);
                self::assertStringNotContainsString('身份验证产物不存在', $result->stdout . $result->stderr);
            } finally {
                $process->stop();
            }
        }
    }

    /** 异常仍可报告原故障，但不能持有已释放的数据库连接或输出凭据参数。 */
    public function testFailureTraceDoesNotRetainTestConnectionOrCredentials(): void
    {
        $controller = <<<'PHP'
require $argv[1];
$connection = new PDO('sqlite::memory:');
$reference = WeakReference::create($connection);
try {
    (static function (PDO $connection, string $credential): void {
        throw new RuntimeException('controlled-http-failure');
    })($connection, 'fixture-private-credential');
} catch (Throwable $failure) {
    $connection = null;
    echo json_encode([
        'released' => $reference->get() === null,
        'message' => $failure->getMessage(),
        'trace' => $failure->getTrace(),
    ], JSON_THROW_ON_ERROR);
}
PHP;
        $process = new Process([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-r', $controller, dirname(__DIR__) . '/support.php']);
        try {
            $result = $process->wait(10);
            self::assertTrue($result->successful(), $result->stderr);
            $report = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($report['released'], '失败调用栈仍持有测试 PDO，阻止数据库清理');
            self::assertSame('controlled-http-failure', $report['message']);
            self::assertNotEmpty($report['trace']);
            foreach ($report['trace'] as $frame) {
                self::assertArrayNotHasKey('args', $frame);
            }
            self::assertStringNotContainsString('fixture-private-credential', $result->stdout);
        } finally {
            $process->stop();
        }
    }

    /** 验证含空格、引号和控制符号的参数保持原样，标准输出、错误输出及非零退出码分别保留。 */
    public function testFixturePreservesArgumentsOutputAndExitWithoutShell(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/test-command-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory . '/space bin', 0700, true));
        try {
            \writeTestPhpCommand($directory . '/space bin/typeapp-fixture', <<<'PHP'
echo json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR);
fwrite(STDERR, "fixture stderr\n");
exit(37);
PHP);
            $arguments = ['', 'two words', '"quoted"', 'C:\\path with space\\', '\\"', '%PATH%', '!PATH!', '&|<>^', '中文'];
            $controller = 'require $argv[1]; require $argv[2]; $command = array_slice($argv, 3); '
                . 'echo json_encode([execute($command), \\TypeApp\\Distribution\\Process::run($command, getcwd())], JSON_THROW_ON_ERROR);';
            $process = new Process(
                [PHP_BINARY, '-r', $controller, $root . '/tests/support.php',
                $root . '/tools/distribution/Process.php', 'typeapp-fixture', ...$arguments],
                $directory,
                \testCommandEnvironment($directory . '/space bin', getenv())
            );
            try {
                $result = $process->wait(15);
                self::assertTrue($result->successful(), $result->stdout . $result->stderr);
                $outputs = json_decode($result->stdout, true, 512, JSON_THROW_ON_ERROR);
                self::assertCount(2, $outputs);
                foreach ($outputs as [$status, $stdout, $stderr]) {
                    self::assertSame(37, $status);
                    self::assertSame($arguments, json_decode($stdout, true, 512, JSON_THROW_ON_ERROR));
                    self::assertSame('fixture stderr', trim($stderr));
                }
            } finally {
                $process->stop();
            }
        } finally {
            \removeTestDirectory($directory);
        }
    }
}
