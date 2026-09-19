<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Testing\Process;

require_once dirname(__DIR__) . '/support.php';

final class TestCommandTest extends TestCase
{
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
