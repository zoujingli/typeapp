<?php

declare(strict_types=1);

/** 生命周期验收使用本轮专用数据库；配置写入私有 .env，不依赖服务管理器继承环境。 */
function serviceDatabaseConfiguration(): string
{
    $driver = getenv('TYPE_SERVICE_DRIVER') ?: 'sqlite';
    expect(in_array($driver, ['sqlite', 'mysql', 'pgsql'], true), '服务验收数据库无效');
    $values = ['DB_DRIVER' => $driver];
    if ($driver === 'sqlite') {
        $values['DB_SQLITE_FILE'] = 'var/app.sqlite';
    } else {
        foreach (['HOST' => 'HOST', 'PORT' => 'PORT', 'DATABASE' => 'DATABASE', 'USERNAME' => 'USER', 'PASSWORD' => 'PASSWORD'] as $target => $source) {
            $value = getenv('TYPE_' . strtoupper($driver) . '_' . $source);
            expect(is_string($value) && $value !== '' && preg_match('/[\x00-\x1f\x7f]/', $value) === 0, '服务验收缺少专用数据库配置');
            $values['DB_' . $target] = $value;
        }
    }
    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
    return implode("\n", $lines) . "\n";
}
