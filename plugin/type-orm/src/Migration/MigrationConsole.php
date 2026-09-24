<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

use Throwable;

/** ORM 数据命令与 core 命令适配器共用的命令行为。 */
final class MigrationConsole
{
    private Migrator $migrator;
    private array $migrations;

    /**
     * 绑定显式迁移计划，构造时不连接数据库。
     *
     * @param list<Migration> $migrations
     */
    public function __construct(Migrator $migrator, array $migrations)
    {
        $this->migrator = $migrator;
        $this->migrations = $migrations;
    }

    /**
     * 执行迁移子命令并输出 JSON；锁冲突退出 75，其他失败退出 70。
     *
     * @param list<string> $arguments 从动作名称开始，不含可执行文件名。
     */
    public function run(array $arguments): int
    {
        try {
            $action = $arguments[0] ?? 'help';
            if ($action === 'help' && count($arguments) <= 1) {
                echo "迁移命令：status、run、history、recover <版本> <retry|applied> <恢复说明>。\n";
                return 0;
            }
            if (in_array($action, ['status', 'run', 'history'], true) && count($arguments) === 1) {
                $result = $action === 'status' ? $this->migrator->status($this->migrations)
                    : ($action === 'run' ? $this->migrator->run($this->migrations) : $this->migrator->history());
            } elseif ($action === 'recover' && count($arguments) === 4) {
                $result = $this->migrator->recover($this->migrations, (string) $arguments[1], (string) $arguments[2], (string) $arguments[3]);
            } else {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移命令参数无效');
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return str_contains($error->getMessage(), 'TYPE_MIGRATION_LOCKED') ? 75 : 70;
        }
    }
}
