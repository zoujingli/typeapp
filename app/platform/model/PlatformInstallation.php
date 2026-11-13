<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * 安装身份与授权互斥行；业务事务只读取并锁定已完成的新安装。
 *
 * @property int $id 安装互斥槽位，固定为 1。
 * @property string $installation_id 全新安装生成的稳定身份。
 * @property int $schema_version 当前安装支持的数据结构版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class PlatformInstallation extends Model
{
    protected string $table = 'platform_installation';

    protected bool $autoIncrement = false;

    protected ?string $createTime = 'created_at';

    protected array $schema = [
        'id' => ['type' => 'integer', 'comment' => '安装互斥槽位，固定为 1。'],
        'installation_id' => ['type' => 'string', 'comment' => '全新安装生成的稳定身份。'],
        'schema_version' => ['type' => 'integer', 'comment' => '当前安装支持的数据结构版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
    ];
}
