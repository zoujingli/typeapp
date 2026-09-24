<?php

declare(strict_types=1);

namespace TypeApp\Rollout;

use Type\Orm\Driver;
use Type\Orm\Migration\Migration;
use Type\Orm\Migration\Migrator;
use Type\Orm\Outbox\Store;

/** 定义扩展、兼容和收缩三个迁移阶段，供新旧版本共同验收。 */
final class Schema
{
    /**
     * 生成截至指定阶段的迁移计划，按驱动保留 DDL 事务差异。
     *
     * @return list<\Type\Orm\Migration\Migration>
     */
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
    /** 从迁移历史读取已成功应用的阶段，不把失败记录当作当前 schema。 */
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
