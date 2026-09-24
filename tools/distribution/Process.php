<?php

declare(strict_types=1);

namespace TypeApp\Distribution;

/** 分发工具的直接子进程和原子报告写入；不经 shell 拼接参数，不属于应用运行时。 */
final class Process
{
    /**
     * 等待命令结束并回收临时输出文件；非零退出由调用方决定是否重试。
     * @param list<string> $command 完整可执行文件与参数。
     * @return array{int, string, string} 退出码、去首尾空白的 stdout 与 stderr。
     * @throws \RuntimeException 无法创建输出文件或启动子进程。
     */
    public static function run(array $command, string $directory): array
    {
        $output = tmpfile();
        $errors = tmpfile();
        if ($output === false || $errors === false) {
            throw new \RuntimeException('无法创建分发输出缓冲');
        }
        try {
            $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $output, 2 => $errors], $pipes, $directory);
            if (!is_resource($process)) {
                throw new \RuntimeException('无法启动分发工具');
            }
            $status = proc_close($process);
            rewind($output);
            rewind($errors);
            return [$status, trim(stream_get_contents($output)), trim(stream_get_contents($errors))];
        } finally {
            fclose($output);
            fclose($errors);
        }
    }
    /**
     * 返回成功命令的 stdout，将失败退出转成带 stderr 的异常。
     * @param list<string> $command 完整可执行文件与参数，不得携带应被日志保密的值。
     * @throws \RuntimeException 命令不能启动或退出码非零。
     */
    public static function output(array $command, string $directory): string
    {
        [$status, $output, $errors] = self::run($command, $directory);
        if ($status !== 0) {
            throw new \RuntimeException('分发工具失败：' . $command[0] . '，' . $errors);
        }
        return $output;
    }
    /**
     * 在目标目录写临时 JSON 后原子替换报告，失败时回收临时文件。
     * @param array<string, mixed> $report 不含凭据的分发身份与操作结果。
     * @throws \RuntimeException 目录、临时文件或最终写入失败。
     */
    public static function report(string $path, array $report): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('无法创建分发报告目录');
        }
        $temporary = tempnam($directory, '.report-');
        if ($temporary === false) {
            throw new \RuntimeException('无法创建分发报告临时文件');
        }
        try {
            $content = json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            if (file_put_contents($temporary, $content) !== strlen($content) || !rename($temporary, $path)) {
                throw new \RuntimeException('无法保存分发报告');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
