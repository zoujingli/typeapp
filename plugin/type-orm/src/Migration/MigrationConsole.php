<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

/**
 * ORM 数据命令与 core 命令适配器共用的命令行为。
 */
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
     * @param list<string> $arguments 从动作名称开始，不含可执行文件名
     */
    public function run(array $arguments): int
    {
        try {
            $action = $arguments[0] ?? 'help';
            if ($action === 'help' && count($arguments) <= 1) {
                echo "迁移命令：status、run/up、pretend、generate <版本> <描述>、rollback/down [步数]、history、recover <版本> <retry|applied> <恢复说明>。\n";
                return 0;
            }
            if (in_array($action, ['status', 'run', 'up', 'history', 'pretend'], true) && count($arguments) === 1) {
                $result = match ($action) {
                    'status' => $this->migrator->status($this->migrations),
                    'history' => $this->migrator->history(),
                    'pretend' => $this->migrator->pretend($this->migrations),
                    default => $this->migrator->run($this->migrations),
                };
            } elseif ($action === 'generate' && count($arguments) === 3) {
                $generated = $this->migrator->generate((string)$arguments[1], (string)$arguments[2]);
                $migration = $generated['migration'];
                $result = ['driver' => $generated['driver'], 'version' => $migration->version(), 'description' => $migration->description(),
                    'transactional' => $migration->transactional(), 'statements' => $migration->statements(),
                    'down' => $migration->downStatements(), 'source' => $generated['script'],
                    'complete' => $generated['complete'], 'integrity' => $generated['integrity'], 'warnings' => $generated['warnings']];
            } elseif (in_array($action, ['rollback', 'down'], true) && (count($arguments) === 1 || count($arguments) === 2)) {
                $steps = count($arguments) === 1 ? 1 : filter_var($arguments[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
                if ($steps === false) {
                    throw new MigrationException('TYPE_MIGRATION_INVALID：回滚步数必须在 1 到 100 之间');
                }
                $result = $this->migrator->rollback($this->migrations, (int)$steps);
            } elseif ($action === 'recover' && count($arguments) === 4) {
                $result = $this->migrator->recover($this->migrations, (string)$arguments[1], (string)$arguments[2], (string)$arguments[3]);
            } else {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移命令参数无效');
            }
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
            return 0;
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . PHP_EOL);
            return str_contains($error->getMessage(), 'TYPE_MIGRATION_LOCKED') ? 75 : 70;
        }
    }
}
