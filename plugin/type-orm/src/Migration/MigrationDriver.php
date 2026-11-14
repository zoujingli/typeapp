<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Type\Orm\Connection;

/**
 * 一种数据库对迁移的结构读取、初始化数据和互斥能力。
 *
 * 新增数据库时实现本接口即可使用现有 up 与 generate，不必再改迁移执行器的库名分支。
 */
interface MigrationDriver
{
    /**
     * DDL 能否与迁移记录放在同一个事务里。
     */
    public function transactionalDdl(): bool;

    /**
     * 当前库是否还没有任何持久对象。
     */
    public function isEmpty(Connection $connection): bool;

    /**
     * 当前连接里是否已有这张表。名称比较沿用该数据库的标识符规则。
     */
    public function tableExists(Connection $connection, string $table): bool;

    /**
     * 指定表里是否已有这一列。名称比较沿用该数据库的标识符规则。
     */
    public function columnExists(Connection $connection, string $table, string $column): bool;

    /**
     * 建立迁移记录表的语句。
     *
     * @return list<string>
     */
    public function migrationTables(string $table): array;

    /**
     * 当前库的完整建表与索引语句，不含迁移记录表。
     *
     * @return list<string>
     */
    public function structure(Connection $connection): array;

    /**
     * 供生成 Blueprint 迁移的表结构。驱动必须返回索引、外键与检查约束；无法表达的特性显式放入 unsupported。
     *
     * @return list<array{name:string,comment:string,columns:list<array<string,mixed>>,primary:list<string>,indexes:list<array{name:string,columns:list<string>,unique:bool}>,foreign:list<array{name?:string,columns:list<string>,on:string,references:list<string>,onDelete?:string|null}>,checks:list<string>,unsupported?:list<string>>>
     */
    public function describe(Connection $connection): array;

    /**
     * 安装标记、平台默认设置和菜单目录的插入语句。
     *
     * @return list<string>
     */
    public function initialization(Connection $connection): array;

    /**
     * 这条语句是否是 SQLite 允许的外键延迟开关。
     */
    public function foreignKeyDeferral(string $sql): bool;

    /**
     * 在互斥边界内执行迁移操作，并在结束时释放锁。
     *
     * @param \Closure(): mixed $operation
     */
    public function lock(Connection $connection, string $table, \Closure $operation): mixed;
}
