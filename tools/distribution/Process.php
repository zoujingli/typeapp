<?php

declare(strict_types=1);

namespace TypeApp\Distribution;

final class Process
{
    public static function run(array $command, string $directory): array
    {
        $output = tmpfile();
        $errors = tmpfile();
        if ($output === false || $errors === false) {
            throw new \RuntimeException('无法创建分发输出缓冲');
        }
        try {
            $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $errors], $pipes, $directory);
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
    public static function output(array $command, string $directory): string
    {
        [$status, $output, $errors] = self::run($command, $directory);
        if ($status !== 0) {
            throw new \RuntimeException('分发工具失败：' . $command[0] . '，' . $errors);
        }
        return $output;
    }
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
