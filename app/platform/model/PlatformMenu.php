<?php

declare(strict_types=1);

namespace app\platform\model;

use Type\Orm\Model;

/**
 * Platform 菜单目录；只绑定构建期已登记的平台页面与权限。
 *
 * @property string $id 记录的稳定标识。
 * @property string $realm 菜单所属身份域。
 * @property null|string $parent_id 上级节点标识；根节点为空。
 * @property string $kind 业务记录类型。
 * @property string $name 业务显示名称。
 * @property string $path 页面或目录的路由路径。
 * @property string $component 构建期登记的页面组件标识。
 * @property null|string $permission 构建期登记的权限码；纯目录节点为空。
 * @property string $icon 菜单图标标识。
 * @property int $order_number 同级节点的显示顺序，数值较小者优先。
 * @property bool $enabled 当前是否启用。
 * @property bool $hidden 是否从导航中隐藏。
 * @property bool $guarded 受保护的系统菜单节点标志。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class PlatformMenu extends Model
{
    protected string $table = 'platform_menu';

    protected bool $autoIncrement = false;

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '记录的稳定标识。'],
        'realm' => ['type' => 'string', 'comment' => '菜单所属身份域。'],
        'parent_id' => ['type' => 'string', 'nullable' => true, 'comment' => '上级节点标识；根节点为空。'],
        'kind' => ['type' => 'string', 'comment' => '业务记录类型。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'path' => ['type' => 'string', 'comment' => '页面或目录的路由路径。'],
        'component' => ['type' => 'string', 'comment' => '构建期登记的页面组件标识。'],
        'permission' => ['type' => 'string', 'nullable' => true, 'comment' => '构建期登记的权限码；纯目录节点为空。'],
        'icon' => ['type' => 'string', 'comment' => '菜单图标标识。'],
        'order_number' => ['type' => 'integer', 'comment' => '同级节点的显示顺序，数值较小者优先。'],
        'enabled' => ['type' => 'boolean', 'comment' => '当前是否启用。'],
        'hidden' => ['type' => 'boolean', 'comment' => '是否从导航中隐藏。'],
        'guarded' => ['type' => 'boolean', 'comment' => '受保护的系统菜单节点标志。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
