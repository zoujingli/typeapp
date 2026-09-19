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

    public function version(): string
    {
        return $this->version;
    }
    public function description(): string
    {
        return $this->description;
    }
    public function statements(): array
    {
        return $this->statements;
    }
    public function transactional(): bool
    {
        return $this->transactional;
    }
    public function checksum(): string
    {
        return $this->checksum;
    }
}
