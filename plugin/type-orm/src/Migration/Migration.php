<?php

declare(strict_types=1);

namespace Type\Orm\Migration;

/** 已编译的迁移内容；版本与 SQL 都不从生产 PHP 文件动态加载。 */
final class Migration
{
    private string $version;
    private string $description;
    private array $statements;
    private bool $transactional;
    private string $checksum;

    /**
     * 固化版本、说明、SQL 与事务策略并计算摘要；已应用版本不可原地修改。
     *
     * @param list<string> $statements 由应用维护的非空 SQL 声明。
     */
    public function __construct(string $version, string $description, array $statements, bool $transactional = true)
    {
        if (!preg_match('/^[0-9][A-Za-z0-9_.-]{0,63}$/D', $version) || $description === '' || strlen($description) > 500 || $statements === []) {
            throw new MigrationException('TYPE_MIGRATION_INVALID：迁移需要合法版本、描述与 SQL');
        }
        foreach ($statements as $statement) {
            if (!is_string($statement) || trim($statement) === '') {
                throw new MigrationException('TYPE_MIGRATION_INVALID：迁移 SQL 必须为非空字符串');
            }
        }
        $this->version = $version;
        $this->description = $description;
        $this->statements = array_values($statements);
        $this->transactional = $transactional;
        $this->checksum = hash('sha256', (string) json_encode([$version, $description, $this->statements, $transactional], JSON_THROW_ON_ERROR));
    }

    /** 返回迁移版本标识，计划按字符串顺序执行。 */
    public function version(): string
    {
        return $this->version;
    }
    /** 返回进入迁移摘要的职责说明，修改说明同样改变身份。 */
    public function description(): string
    {
        return $this->description;
    }
    /**
     * 按声明顺序取得编译输入中的 SQL，不加载外部生产 PHP 文件。
     *
     * @return list<string>
     */
    public function statements(): array
    {
        return $this->statements;
    }
    /** 声明是否要求 SQL 与迁移结果原子提交；MySQL 迁移必须为 false。 */
    public function transactional(): bool
    {
        return $this->transactional;
    }
    /** 取得覆盖版本、说明、SQL 和事务策略的 SHA-256，用于检测历史被改写。 */
    public function checksum(): string
    {
        return $this->checksum;
    }
}
