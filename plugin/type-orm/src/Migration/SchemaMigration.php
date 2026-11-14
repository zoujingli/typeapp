<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Type\Orm\Schema\Schema;

/**
 * 用 Schema 与 Blueprint 编写的迁移。
 *
 * up 与 down 在执行时看到真实数据库，因此可以用 hasTable、hasColumn 保持幂等。
 * 预演和校验和使用同一份结构声明在“表尚不存在”时会生成的 SQL。
 */
abstract class SchemaMigration
{
    private string $scriptVersion = '';

    /**
     * 版本只来自迁移文件名，脚本里不再声明具名类。
     */
    final public function bindVersion(string $version): void
    {
        if (!preg_match('/^[0-9]{3}_[a-z][a-z0-9_]{0,60}$/D', $version)) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移文件名必须是 序号_名称：' . $version);
        }
        if ($this->scriptVersion !== '') {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移版本不能重复绑定：' . $version);
        }
        $this->scriptVersion = $version;
    }

    /**
     * 返回文件名绑定的版本。脚本不要再声明固定类名或自行覆盖版本。
     */
    public function version(): string
    {
        if ($this->scriptVersion === '') {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移版本必须来自文件名');
        }
        return $this->scriptVersion;
    }

    /**
     * 给人看的迁移说明，并参与校验和。
     */
    abstract public function description(): string;

    /**
     * 正向变更。预演时 hasTable 为假，执行时按当前库跳过已有对象。
     */
    abstract public function up(Schema $schema): void;

    /**
     * 作者编写的回滚。空实现不生成 down SQL。
     */
    public function down(Schema $schema): void {}

    /**
     * 按当前驱动预演 up/down，得到可执行的 SQL 迁移。
     */
    final public function compile(string $driver): Migration
    {
        $up = new Schema($driver, null, true);
        $this->up($up);
        $down = new Schema($driver, null, true);
        $this->down($down);
        if ($up->statements() === []) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移 up 没有生成 SQL：' . $this->version());
        }
        return new Migration($this->version(), $this->description(), $up->statements(), $driver !== 'mysql', $down->statements());
    }
}
