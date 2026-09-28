<?php

declare(strict_types=1);

use Type\Build\BuildLock;
use Type\Build\BuildPlatform;
use Type\Testing\Process;

/** Windows 验收专用：用唯一限制 SID 阻断源码与 SDK，保存并恢复原 ACL。 */
final class WindowsNativeSandbox
{
    private string $work;
    private string $specification;
    private string $powershell;
    private string $script;
    private array $command;

    /** 本轮目录由测试创建；仅子进程受限，控制端仍可读取报告和清理资源。 */
    public function __construct(string $root, string $package, string $runtime)
    {
        expect(PHP_OS_FAMILY === 'Windows', 'Windows 隔离只能在原生 Windows 执行');
        foreach ([$root, $package, $runtime] as $directory) {
            BuildLock::path($directory);
        }
        $root = BuildPlatform::resolve($root);
        $package = BuildPlatform::resolve($package);
        $runtime = BuildPlatform::resolve($runtime);
        expect(BuildPlatform::contains($root . '/build', $package) && BuildPlatform::contains($root . '/build', $runtime), '隔离只接受本轮 build 目录');
        $this->work = $root . '/build/windows-sandbox-' . bin2hex(random_bytes(6));
        expect(mkdir($this->work, 0700), '无法创建 Windows 隔离控制目录');
        $this->specification = $this->work . '/specification.json';
        $this->powershell = (string) getenv('SystemRoot') . '/System32/WindowsPowerShell/v1.0/powershell.exe';
        $this->script = $root . '/tests/native-windows-sandbox.ps1';
        $runner = $this->work . '/restricted-runner.exe';
        $compile = (new Process(['cl.exe', '/nologo', '/MT', '/EHsc', '/std:c++17', '/utf-8',
            $root . '/tests/native-windows-sandbox.cpp', '/Fo' . $this->work . '/runner.obj', '/Fe' . $runner,
            '/link', 'advapi32.lib'], $this->work))->wait(60);
        expect($compile->successful(), 'Windows 隔离设置器编译失败：' . $compile->stdout . $compile->stderr);
        $found = successful([(string) getenv('SystemRoot') . '/System32/where.exe', 'cl.exe'], $root);
        $compiler = BuildPlatform::resolve(trim(explode("\n", $found)[0]));
        $host = BuildPlatform::resolve((string) getenv('PHP_HOME'));
        $phpx = BuildPlatform::resolve((string) getenv('PHPX_HOME'));
        $blocked = [$root . '/app/main.php', $root . '/vendor/autoload.php', $host . '/php.exe', $host . '/php8ts.dll', $compiler];
        $directories = [$root, $host, $phpx, dirname($compiler)];
        $staticManifest = getenv('TYPE_STATIC_RUNTIME');
        if (is_string($staticManifest) && $staticManifest !== '') {
            $staticRoot = dirname(BuildPlatform::resolve($staticManifest));
            $directories[] = $staticRoot;
            $blocked[] = $staticRoot . '/include/php/main/php.h';
        }
        $node = (new Process([(string) getenv('SystemRoot') . '/System32/where.exe', 'node.exe'], $root))->wait(10);
        if ($node->successful()) {
            $nodePath = BuildPlatform::resolve(trim(explode("\n", $node->stdout)[0]));
            $directories[] = dirname($nodePath);
            $blocked[] = $nodePath;
        }
        $sid = 'S-1-5-21-' . random_int(100000000, 2000000000) . '-' . random_int(100000000, 2000000000)
            . '-' . random_int(100000000, 2000000000) . '-12345';
        $changes = [];
        foreach (array_unique($directories) as $directory) {
            $changes[] = ['path' => $directory, 'access' => 'deny-read'];
        }
        $changes[] = ['path' => $package, 'access' => 'read'];
        $changes[] = ['path' => $runner, 'access' => 'read'];
        $changes[] = ['path' => $runtime, 'access' => 'modify'];
        file_put_contents($this->specification, json_encode(['sid' => $sid, 'changes' => $changes], JSON_THROW_ON_ERROR));
        $this->command = [$runner, $sid, $package . '/app.exe'];
        try {
            $this->acl('prepare');
            foreach ($blocked as $file) {
                expect(is_file($file) && is_readable($file), 'Windows 隔离负向探针输入缺失或控制端不可读');
            }
            $probe = (new Process(
                [$runner, $sid, $runner, '--probe', $package . '/app.exe', $runtime, $package, ...$blocked],
                $runtime,
                self::environment($runtime)
            ))->wait(30);
            expect($probe->successful(), 'Windows 隔离未阻断源码/SDK或未开放受控目录：' . $probe->stdout . $probe->stderr);
        } catch (Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @return list<string> 业务参数仍由调用方以数组追加。 */
    public function command(): array
    {
        return $this->command;
    }

    /** @return array<string,string> 控制端 PHP、Node、Composer 与 MSVC 不出现在部署 PATH。 */
    public static function environment(string $runtime): array
    {
        return ['SystemRoot' => (string) getenv('SystemRoot'), 'PATH' => (string) getenv('SystemRoot') . '/System32',
            'TEMP' => $runtime, 'TMP' => $runtime];
    }

    /** 恢复原 ACL 后才删除控制目录；失败时保留恢复账本。 */
    public function close(): void
    {
        if (is_file($this->specification . '.acl.json')) {
            $this->acl('restore');
        }
        if (is_dir($this->work)) {
            removeTestDirectory($this->work);
        }
    }

    private function acl(string $operation): void
    {
        $result = (new Process([$this->powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
            '-File', $this->script, $operation, $this->specification]))->wait(120);
        expect($result->successful(), 'Windows 隔离 ACL ' . $operation . ' 失败：' . $result->stdout . $result->stderr);
    }
}
