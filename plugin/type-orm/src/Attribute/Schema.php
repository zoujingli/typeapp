<?php

declare(strict_types=1);

namespace Type\Orm\Attribute;

use Attribute;

/** 构建期迁移声明；显式准备三库快照后生成同名 migration(driver) 工厂，生产不读取快照文件。 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Schema
{
    /**
     * @param string $version 新迁移版本，已应用版本不得修改。
     * @param string $description 纳入既有迁移身份的职责说明。
     * @param list<array<string, mixed>> $operations 创建或原生结构变化的常量声明，保持执行顺序。
     * @param string $snapshot 相对于本声明文件的冻结 JSON 路径，由 type schema:prepare 显式创建。
     */
    public function __construct(public string $version, public string $description, public array $operations, public string $snapshot)
    {
    }
}
