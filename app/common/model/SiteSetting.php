<?php

declare(strict_types=1);

namespace app\common\model;

use Type\Orm\Attribute\Column;
use Type\Orm\Attribute\Table;
use Type\Orm\Model;

/** 全局站点品牌与界面偏好；单例由迁移初始化，业务保存遵守乐观版本约束。 */
#[Table('app_site_settings', generatedPrimary: false, version: 'version', createdAt: 'created_at', updatedAt: 'updated_at')]
final class SiteSetting extends Model
{
    #[Column(fillable: false)]
    public int $id;

    public string $name;
    public string $official_url;
    public string $description;
    public string $logo_url;
    public string $timezone;
    public string $theme_mode;
    public string $theme_color;
    public string $theme_radius;
    public string $layout_mode;
    public int $sidebar_collapsed;
    public string $navigation_style;
    public int $navigation_split;
    public int $breadcrumb_enable;
    public int $breadcrumb_show_icon;
    public string $breadcrumb_style;
    public int $tabbar_enable;
    public string $tabbar_style;
    public int $footer_enable;
    public int $footer_fixed;

    #[Column(fillable: false, required: false)]
    public int $version;

    #[Column(fillable: false)]
    public int $created_at;

    public int $updated_at;
}
