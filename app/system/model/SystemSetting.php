<?php

declare(strict_types=1);

namespace app\system\model;

use Type\Orm\Model;

/**
 * 客户独立品牌与界面偏好；只在已验证的 Tenant 执行范围内读取和保存。
 *
 * @property int $id 当前 Tenant 的设置槽位，固定为 1。
 * @property-read string $tenant_id 所属 Tenant 标识，由可信执行上下文约束。
 * @property string $name 业务显示名称。
 * @property string $official_url 公开展示的官方网站地址。
 * @property string $description 供管理人员阅读的说明。
 * @property string $logo_url 品牌标识资源地址，空值采用缺省品牌。
 * @property string $timezone 界面默认使用的 IANA 时区名称。
 * @property string $theme_mode 默认主题模式：light、dark 或 auto。
 * @property string $theme_color 默认主题主色，六位十六进制颜色。
 * @property string $theme_radius 默认组件圆角配置。
 * @property string $layout_mode 默认工作区布局模式。
 * @property int $sidebar_collapsed 侧栏默认折叠状态。
 * @property string $navigation_style 默认导航外观。
 * @property int $navigation_split 混合导航是否拆分显示。
 * @property int $breadcrumb_enable 是否默认显示面包屑。
 * @property int $breadcrumb_show_icon 面包屑是否默认显示图标。
 * @property string $breadcrumb_style 默认面包屑样式。
 * @property int $tabbar_enable 是否默认启用标签页。
 * @property string $tabbar_style 默认标签页样式。
 * @property int $footer_enable 是否默认显示页脚。
 * @property int $footer_fixed 页脚是否默认固定。
 * @property-read int $version 资料并发版本。
 * @property-read int $created_at 创建时间，UTC Unix 秒。
 * @property-read int $updated_at 最后更新时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 */
final class SystemSetting extends Model
{
    protected string $table = 'system_setting';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected ?string $versionKey = 'version';

    protected ?string $createTime = 'created_at';

    protected ?string $updateTime = 'updated_at';

    protected array $schema = [
        'id' => ['type' => 'integer', 'comment' => '当前 Tenant 的设置槽位，固定为 1。'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束。'],
        'name' => ['type' => 'string', 'comment' => '业务显示名称。'],
        'official_url' => ['type' => 'string', 'comment' => '公开展示的官方网站地址。'],
        'description' => ['type' => 'string', 'comment' => '供管理人员阅读的说明。'],
        'logo_url' => ['type' => 'string', 'comment' => '品牌标识资源地址，空值采用缺省品牌。'],
        'timezone' => ['type' => 'string', 'comment' => '界面默认使用的 IANA 时区名称。'],
        'theme_mode' => ['type' => 'string', 'comment' => '默认主题模式：light、dark 或 auto。'],
        'theme_color' => ['type' => 'string', 'comment' => '默认主题主色，六位十六进制颜色。'],
        'theme_radius' => ['type' => 'string', 'comment' => '默认组件圆角配置。'],
        'layout_mode' => ['type' => 'string', 'comment' => '默认工作区布局模式。'],
        'sidebar_collapsed' => ['type' => 'integer', 'comment' => '侧栏默认折叠状态。'],
        'navigation_style' => ['type' => 'string', 'comment' => '默认导航外观。'],
        'navigation_split' => ['type' => 'integer', 'comment' => '混合导航是否拆分显示。'],
        'breadcrumb_enable' => ['type' => 'integer', 'comment' => '是否默认显示面包屑。'],
        'breadcrumb_show_icon' => ['type' => 'integer', 'comment' => '面包屑是否默认显示图标。'],
        'breadcrumb_style' => ['type' => 'string', 'comment' => '默认面包屑样式。'],
        'tabbar_enable' => ['type' => 'integer', 'comment' => '是否默认启用标签页。'],
        'tabbar_style' => ['type' => 'string', 'comment' => '默认标签页样式。'],
        'footer_enable' => ['type' => 'integer', 'comment' => '是否默认显示页脚。'],
        'footer_fixed' => ['type' => 'integer', 'comment' => '页脚是否默认固定。'],
        'version' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '资料并发版本。'],
        'created_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '创建时间，UTC Unix 秒。'],
        'updated_at' => ['type' => 'integer', 'fillable' => false, 'required' => false, 'comment' => '最后更新时间，UTC Unix 秒。'],
    ];
}
