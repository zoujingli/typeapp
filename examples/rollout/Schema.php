<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

use Type\Orm\Driver;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Orm\Outbox\Store;

final class Schema
{
    public static function plan(Driver $driver, int $phase): array
    {
        if ($phase < 1 || $phase > 3) {
            throw new \InvalidArgumentException('兼容演练迁移阶段无效');
        }
        $transactional = $driver->name() !== 'mysql';
        $engine = $transactional ? '' : ' ENGINE=InnoDB';
        $migrations = [(new Store())->migration($driver->name(), '010_outbox'), new Migration('100_initial', '创建初始兼容业务表', [
            'CREATE TABLE rollout_users (id VARCHAR(64) PRIMARY KEY, name VARCHAR(100) NOT NULL)' . $engine,
            'CREATE TABLE type_outbox_effects (id VARCHAR(128) PRIMARY KEY, value VARCHAR(100) NOT NULL)' . $engine,
        ], $transactional)];
        if ($phase >= 2) {
            $migrations[] = new Migration('200_expand', '兼容扩展昵称字段并核对发布前置条件', [
                'ALTER TABLE rollout_users ADD COLUMN nickname VARCHAR(100) NULL',
                "INSERT INTO rollout_gate (id) VALUES ('expanded')",
            ], $transactional);
        }
        if ($phase >= 3) {
            $migrations[] = new Migration('300_contract', '旧版排空后回填并移除过期字段', ['UPDATE rollout_users SET nickname = name', 'ALTER TABLE rollout_users DROP COLUMN name'], $transactional);
        }
        return $migrations;
    }
    public static function current(Driver $driver): int
    {
        $version = 0;
        foreach ((new Migrator($driver))->history() as $event) {
            if (!in_array($event['state'], ['applied', 'recovered_applied'], true)) {
                continue;
            }
            $number = ['100_initial' => 1, '200_expand' => 2, '300_contract' => 3][$event['version']] ?? 0;
            $version = max($version, $number);
        }
        return $version;
    }
}
