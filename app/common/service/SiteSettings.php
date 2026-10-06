<?php

declare(strict_types=1);

namespace app\common\service;

use app\common\model\SiteSetting;
use Type\Core\Http\HttpError;
use Type\Orm\Db;
use Type\Orm\Model;

/**
 * 物联中心站点设置的唯一持久所有者。
 *
 * 站点设置属于业务数据，不读取或改写运行时 `.env`；公开投影只包含登录页
 * 所需的品牌和默认界面偏好，管理写入使用版本号避免并发覆盖。
 */
final class SiteSettings
{
    /** @return array{name: string, official_url: string, description: string, logo_url: string, timezone: string, theme_mode: string, theme_color: string, theme_radius: string, layout_mode: string, sidebar_collapsed: int, navigation_style: string, navigation_split: int, breadcrumb_enable: int, breadcrumb_show_icon: int, breadcrumb_style: string, tabbar_enable: int, tabbar_style: string, footer_enable: int, footer_fixed: int} */
    public static function defaults(): array
    {
        return [
            'name' => 'TypeApp',
            'official_url' => 'https://iots.top',
            'description' => '物联中心管理平台',
            'logo_url' => '',
            'timezone' => 'Asia/Shanghai',
            'theme_mode' => 'light',
            'theme_color' => '#1677ff',
            'theme_radius' => '0.5',
            'layout_mode' => 'sidebar-nav',
            'sidebar_collapsed' => 0,
            'navigation_style' => 'rounded',
            'navigation_split' => 1,
            'breadcrumb_enable' => 1,
            'breadcrumb_show_icon' => 1,
            'breadcrumb_style' => 'normal',
            'tabbar_enable' => 0,
            'tabbar_style' => 'chrome',
            'footer_enable' => 0,
            'footer_fixed' => 0,
        ];
    }

    /** @return array<string, mixed> 从主库读取公开投影，使保存后的登录页立即采用当前设置。 */
    public static function publicView(): array
    {
        return self::view(self::row(), false);
    }

    /** @return array<string, mixed> 管理端投影包含并发版本和更新时间，但不暴露单例主键。 */
    public static function adminView(): array
    {
        return self::view(self::row(), true);
    }

    /**
     * 在调用方已有授权事务中更新站点设置；所有可写字段均在此处再次校验。
     *
     * @param array<string, mixed> $changes 只接受固定字段，不接受任意主题或脚本内容。
     * @return array<string, mixed> 更新后的管理端投影和实际变化键。
     * @throws \LogicException 调用方未建立授权事务。
     * @throws HttpError 输入非法、尚未完成安装或读取的版本已过期。
     */
    public static function update(int $version, array $changes): array
    {
        if ($version < 1 || $changes === []) {
            throw new HttpError(422, 'site_settings_input_invalid');
        }
        $normalized = self::normalize($changes);
        $connection = Db::connection('default', true);
        if ($connection->transactionDepth() < 1) {
            throw new \LogicException('site_settings_requires_transaction');
        }
        $query = SiteSetting::query()->master()->where('id', '=', 1);
        $model = ($connection->driverName() === 'sqlite' ? $query : $query->lockForUpdate())->first();
        if ($model === null) {
            throw new HttpError(503, 'installation_incomplete');
        }
        if ($model->get('version') !== $version) {
            throw new HttpError(409, 'stale_version');
        }
        $model->fill($normalized);
        // 相同设置再次保存只推进版本；资料变化由 Model 同时维护更新时间。
        $model->dirty() === [] ? $model->touch() : $model->save();
        return self::view($model, true) + ['changed' => array_keys($normalized)];
    }

    /** 站点是全局业务单例；没有对应记录时不以默认值掩盖未完成的安装。 */
    private static function row(): Model
    {
        $model = SiteSetting::query()->master()->find(1);
        if ($model === null) {
            throw new HttpError(503, 'installation_incomplete');
        }
        return $model;
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private static function normalize(array $changes): array
    {
        $allowed = ['name', 'official_url', 'description', 'logo_url', 'timezone', 'theme_mode', 'theme_color', 'theme_radius',
            'layout_mode', 'sidebar_collapsed', 'navigation_style', 'navigation_split', 'breadcrumb_enable', 'breadcrumb_show_icon',
            'breadcrumb_style', 'tabbar_enable', 'tabbar_style', 'footer_enable', 'footer_fixed'];
        $booleanKeys = ['sidebar_collapsed', 'navigation_split', 'breadcrumb_enable', 'breadcrumb_show_icon', 'tabbar_enable', 'footer_enable', 'footer_fixed'];
        if (array_diff(array_keys($changes), $allowed) !== []) {
            throw new HttpError(422, 'site_settings_input_invalid');
        }
        $result = [];
        foreach ($changes as $key => $value) {
            if (in_array($key, $booleanKeys, true)) {
                if (!is_bool($value)) {
                    throw new HttpError(422, 'site_settings_value_invalid');
                }
                $result[$key] = $value ? 1 : 0;
                continue;
            }
            if (!is_string($value)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            $value = trim($value);
            if ($key === 'name' && ($value === '' || strlen($value) > 100)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'official_url' && !self::url($value, false)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'description' && strlen($value) > 500) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'logo_url' && !self::url($value, true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'timezone' && preg_match('/^[A-Za-z_]+(?:\/[A-Za-z0-9_+.-]+)+$/D', $value) !== 1) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'theme_mode' && !in_array($value, ['light', 'dark', 'auto'], true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'theme_color' && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) !== 1) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'theme_radius' && !in_array($value, ['0', '0.25', '0.5', '0.75', '1'], true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'layout_mode' && !in_array($value, ['sidebar-nav', 'mixed-nav', 'header-nav'], true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'navigation_style' && !in_array($value, ['plain', 'rounded'], true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'breadcrumb_style' && !in_array($value, ['normal', 'background'], true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            if ($key === 'tabbar_style' && !in_array($value, ['brisk', 'card', 'chrome', 'plain'], true)) {
                throw new HttpError(422, 'site_settings_value_invalid');
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private static function url(string $value, bool $allowPath): bool
    {
        if ($value === '') {
            return $allowPath;
        }
        if ($allowPath && str_starts_with($value, '/') && !str_starts_with($value, '//')) {
            return strlen($value) <= 512 && preg_match('/[\x00-\x20]/', $value) !== 1;
        }
        $url = filter_var($value, FILTER_VALIDATE_URL);
        if ($url === false || strlen($value) > 512) {
            return false;
        }
        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) && (string) parse_url($value, PHP_URL_HOST) !== '';
    }

    /** @return array<string, mixed> 只输出固定业务字段，不把完整模型或持久化身份暴露给客户端。 */
    private static function view(Model $model, bool $admin): array
    {
        $result = [
            'name' => (string) $model->get('name'),
            'official_url' => (string) $model->get('official_url'),
            'description' => (string) $model->get('description'),
            'logo_url' => (string) $model->get('logo_url'),
            'timezone' => (string) $model->get('timezone'),
            'theme' => [
                'mode' => (string) $model->get('theme_mode'),
                'colorPrimary' => (string) $model->get('theme_color'),
                'radius' => (string) $model->get('theme_radius'),
            ],
            'preferences' => [
                'layout' => (string) $model->get('layout_mode'),
                'sidebar' => ['collapsed' => (bool) $model->get('sidebar_collapsed')],
                'navigation' => ['styleType' => (string) $model->get('navigation_style'), 'split' => (bool) $model->get('navigation_split')],
                'breadcrumb' => ['enable' => (bool) $model->get('breadcrumb_enable'), 'showIcon' => (bool) $model->get('breadcrumb_show_icon'), 'styleType' => (string) $model->get('breadcrumb_style')],
                'tabbar' => ['enable' => (bool) $model->get('tabbar_enable'), 'styleType' => (string) $model->get('tabbar_style')],
                'footer' => ['enable' => (bool) $model->get('footer_enable'), 'fixed' => (bool) $model->get('footer_fixed')],
            ],
        ];
        if ($admin) {
            $result['version'] = (int) $model->get('version');
            $result['updated_at'] = (int) $model->get('updated_at');
        }
        return $result;
    }
}
