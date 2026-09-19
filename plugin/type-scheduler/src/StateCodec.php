<?php

declare(strict_types=1);

namespace Type\Scheduler;

/** @internal 文件与 Redis 存储复用同一个状态协议和容量限制。 */
final class StateCodec
{
    public static function decode(string $json): array
    {
        if (strlen($json) > 16777216) {
            throw new \RuntimeException('TYPE_SCHEDULER_STORE：状态文件读取失败或超过 16 MiB');
        }
        $state = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['protocol'] ?? null) !== 1 || !is_array($state['cursors'] ?? null) || !is_array($state['records'] ?? null)) {
            throw new \RuntimeException('TYPE_SCHEDULER_STORE：状态文件格式不兼容');
        }
        foreach ($state['cursors'] as $cursor) {
            if (!is_int($cursor)) {
                throw new \RuntimeException('TYPE_SCHEDULER_STORE：状态游标必须为 UTC 秒');
            }
        }
        foreach ($state['records'] as $record) {
            if (!is_array($record) || !is_string($record['occurrence_id'] ?? null) || !is_string($record['task_id'] ?? null)
                || !is_int($record['scheduled_at'] ?? null) || !is_int($record['started_at'] ?? null)
                || !in_array($record['state'] ?? '', ['running', 'succeeded', 'failed', 'interrupted'], true)) {
                throw new \RuntimeException('TYPE_SCHEDULER_STORE：执行历史损坏，停止调度以保留现场');
            }
        }

        return $state;
    }

    public static function encode(array $state): string
    {
        $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 16777216) {
            throw new \RuntimeException('TYPE_SCHEDULER_STORE：状态超过 16 MiB，请减少保留记录或任务数量');
        }
        return $json;
    }
}
