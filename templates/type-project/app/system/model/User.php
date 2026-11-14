<?php

declare(strict_types=1);

namespace app\system\model;

use Type\Orm\Model;
use Type\Orm\ModelException;

/**
 * 用户字段由 table/schema 声明，业务方法与类名在构建后保持不变。
 *
 * @property int $id 记录标识。
 * @property string $name 显示名称。
 * @property int $age 年龄。
 * @property string|null $email 联系邮箱。
 * @property int $version 并发版本。
 * @property \DateTimeImmutable|null $deleted_at 软删除时间。
 */
final class User extends Model
{
    protected string $table = 'users';

    protected ?string $versionKey = 'version';

    protected ?string $deleteTime = 'deleted_at';

    protected array $schema = [
        'id' => ['type' => 'int', 'comment' => '记录标识。'],
        'name' => ['type' => 'string', 'comment' => '显示名称。'],
        'age' => ['type' => 'int', 'comment' => '年龄。'],
        'email' => ['type' => '?string', 'required' => false, 'comment' => '联系邮箱。'],
        'version' => ['type' => 'int', 'fillable' => false, 'required' => false, 'comment' => '并发版本。'],
        'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false, 'comment' => '软删除时间。'],
    ];

    /**
     * 对外投影显式选择字段，不暴露内部软删除标记。
     *
     * @return array{id: int, name: string, age: int, email: ?string, version: int}
     * @throws ModelException 模型已失效或投影字段尚未加载
     */
    public function present(): array
    {
        return $this->project(['id', 'name', 'age', 'email', 'version']);
    }
}
