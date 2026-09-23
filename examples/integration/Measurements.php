<?php

declare(strict_types=1);

namespace TypeApp\Integration;

use Type\Orm\DatabaseManager;

/** 固定业务输入下的有界采样；记录环境与原始窗口，不推断通用性能倍数。 */
final class Measurements
{
    public static function run(DatabaseManager $database, int $articleId): array
    {
        for ($warmup = 0; $warmup < 50; $warmup++) {
            Reader::article($articleId);
        }
        $samples = [];
        $latencies = [];
        $started = hrtime(true);
        $baseline = memory_get_usage(true);
        memory_reset_peak_usage();
        for ($iteration = 0; $iteration < 500; $iteration++) {
            $before = hrtime(true);
            $row = Reader::article($articleId);
            if ($row['views'] !== 1 || $row['status'] !== 'audited') {
                throw new \RuntimeException('测量期间文章业务结果变化');
            }
            $latencies[] = (hrtime(true) - $before) / 1000000;
            if (($iteration + 1) % 50 === 0) {
                $samples[] = ['completed' => $iteration + 1, 'elapsed_seconds' => (hrtime(true) - $started) / 1000000000,
                    'zend_bytes' => memory_get_usage(true), 'rss_bytes' => self::rss(), 'pool' => $database->statistics()];
            }
        }
        $elapsed = (hrtime(true) - $started) / 1000000000;
        // TypePHP 将除法降为原生运算；SQLite 等极快路径下 elapsed 可能为 0，直接触发 SIGFPE。
        $rate = $elapsed > 0.0 ? 500 / $elapsed : 0.0;
        sort($latencies, SORT_NUMERIC);
        $usage = getrusage();
        return ['workload' => '单连接重复查询同一文章及作者、多对多标签，校验结果；每 50 次采样', 'warmup_iterations' => 50,
            'iterations' => 500, 'concurrency' => 1, 'elapsed_seconds' => $elapsed, 'operations_per_second' => $rate,
            'latency_ms' => ['p50' => $latencies[249], 'p95' => $latencies[474], 'p99' => $latencies[494]], 'errors' => 0,
            'zend_peak_delta_bytes' => max(0, memory_get_peak_usage(true) - $baseline), 'samples' => $samples,
            'process_peak_rss' => $usage['ru_maxrss'], 'rss_unit' => PHP_OS_FAMILY === 'Darwin' ? 'bytes' : 'KiB',
            'environment' => self::environment()];
    }

    private static function rss(): ?int
    {
        $status = self::read('/proc/self/status');
        $matches = [];
        return preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $matches) === 1 ? (int) $matches[1] * 1024 : null;
    }

    private static function environment(): array
    {
        $cpu = self::read('/proc/cpuinfo');
        $matches = [];
        $memory = [];
        preg_match('/^(?:model name|Hardware)\s*:\s*(.+)$/m', $cpu, $matches);
        preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', self::read('/proc/meminfo'), $memory);
        return ['os' => PHP_OS_FAMILY, 'architecture' => php_uname('m'), 'kernel' => php_uname('r'),
            'php' => PHP_VERSION, 'zts' => (bool) PHP_ZTS, 'swoole' => phpversion('swoole'), 'redis' => phpversion('redis'),
            'cpu_model' => $matches[1] ?? '未由平台提供', 'logical_cpus' => preg_match_all('/^processor\s*:/m', $cpu),
            'system_memory_bytes' => isset($memory[1]) ? (int) $memory[1] * 1024 : null,
            'cgroup_cpu_max' => trim(self::read('/sys/fs/cgroup/cpu.max')), 'cgroup_memory_max' => trim(self::read('/sys/fs/cgroup/memory.max'))];
    }

    private static function read(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }
        $value = file_get_contents($path);
        return $value === false ? '' : $value;
    }
}
