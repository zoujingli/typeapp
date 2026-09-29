<?php

declare(strict_types=1);

/** 最终应用命令执行真实审计清理，Redis 游标跨命令和服务崩溃保持，不以组件测试代替。 */
function iotSchedulerChecks(PDO $database, array $command, array $environment, string $base): array
{
    require_once __DIR__ . '/native-rollout-redis.php';
    $redis = new NativeRolloutRedis($base . '/scheduler-redis', (string) getenv('TYPE_REDIS_SERVER'));
    $parameters = $redis->environment();
    $environment = array_replace($environment, [
        'TYPE_APP_TRACE' => '1',
        'APP_SCHEDULER_NAMESPACE' => basename($base), 'APP_SCHEDULER_REDIS_HOST' => $parameters['TYPE_REDIS_HOST'],
        'APP_SCHEDULER_REDIS_PORT' => $parameters['TYPE_REDIS_PORT'],
    ]);
    $run = static function (string $action) use ($command, $environment): array {
        $process = new Type\Testing\Process([...$command, 'app:schedule', $action], dirname(__DIR__), $environment);
        try {
            $result = $process->wait(60);
            expect($result->successful(), '应用调度失败：' . $result->stdout . $result->stderr);
            return json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } finally {
            $process->stop();
        }
    };
    try {
        expect($run('history') === [], '本轮调度读到了其他应用的历史');
        $ids = [];
        foreach (['admin', 'customer'] as $realm) {
            $id = $database->query('SELECT id FROM ' . $realm . '_audit ORDER BY id LIMIT 1')->fetchColumn();
            expect(is_string($id), '本轮业务没有生成可用于维护验收的审计');
            $statement = $database->prepare('UPDATE ' . $realm . '_audit SET created_at = ? WHERE id = ?');
            $statement->execute([time() - 181 * 86400, $id]);
            $ids[$realm] = $id;
        }
        $first = $run('once');
        expect(array_column($first, 'task_id') === ['audit.admin', 'audit.customer']
            && array_column($first, 'state') === ['succeeded', 'succeeded'], '原生调度没有执行两个真实维护任务');
        foreach (['admin', 'customer'] as $index => $realm) {
            expect($first[$index]['result']['deleted'] === 1, '原生调度未按保留期删除准确记录');
            $statement = $database->prepare('SELECT COUNT(*) FROM ' . $realm . '_audit WHERE id = ?');
            $statement->execute([$ids[$realm]]);
            expect((int) $statement->fetchColumn() === 0, '调度声称成功但业务效果未提交');
            expect((int) $database->query('SELECT COUNT(*) FROM ' . $realm . '_audit')->fetchColumn() > 0, '调度误删除了未到期审计');
        }
        expect($run('history') === $first, '命令重启丢失调度结果');
        $redis->crashAndRestartReliable();
        expect($run('history') === $first, '真实 Redis 崩溃恢复丢失了持久游标或结果');
        $second = $run('once');
        $history = $run('history');
        $occurrences = array_column($history, 'occurrence_id');
        expect(count($occurrences) === count(array_unique($occurrences)) && count($history) === count($first) + count($second), '调度重复执行已完成的计划时刻');
        foreach ($second as $record) {
            expect($record['state'] === 'succeeded' && $record['result']['deleted'] === 0, '下一分钟调度改变已清理事实');
        }
        return ['status' => 'passed', 'tasks' => array_column($first, 'task_id'), 'deleted' => 2,
            'restart-persisted' => true, 'unique-occurrences' => true, 'redis' => $redis->evidence()];
    } finally {
        $redis->close();
    }
}
