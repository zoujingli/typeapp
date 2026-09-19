<?php

declare(strict_types=1);

namespace app\common\service;

use Type\Core\Http\HttpError;
use Type\Orm\Connection;

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

    /** @return array<string, mixed> */
    public static function publicView(Connection $connection): array
    {
        return self::view(self::row($connection, false), false);
    }

    /** @return array<string, mixed> 管理端投影包含并发版本和更新时间，但不暴露单例主键。 */
    public static function adminView(Connection $connection): array
    {
        return self::view(self::row($connection, false), true);
    }

    /**
     * 在调用方已有授权事务中更新站点设置；所有可写字段均在此处再次校验。
     *
     * @param array<string, mixed> $changes 只接受固定字段，不接受任意主题或脚本内容。
     * @return array<string, mixed> 更新后的管理端投影和实际变化键。
     */
    public static function update(Connection $connection, int $version, array $changes): array
    {
        if ($version < 1 || $changes === []) {
            throw new HttpError(422, 'site_settings_input_invalid');
        }
        $normalized = self::normalize($changes);
        $query = $connection->table('app_site_settings')->where('id', '=', 1);
        $row = $connection->driverName() === 'sqlite' ? $query->first() : $query->lockForUpdate()->first();
        if ($row === null) {
            throw new HttpError(503, 'installation_incomplete');
        }
        if ((int) $row['version'] !== $version) {
            throw new HttpError(409, 'stale_version');
        }
        $now = time();
        $values = $normalized + ['version' => $version + 1, 'updated_at' => $now];
        $query->update($values);
        return self::view(array_replace($row, $values), true) + ['changed' => array_keys($normalized)];
    }

    /** @return array<string, mixed> */
    private static function row(Connection $connection, bool $lock): array
    {
        $query = $connection->table('app_site_settings')->where('id', '=', 1);
        $row = $lock && $connection->driverName() !== 'sqlite' ? $query->lockForUpdate()->first() : $query->first();
        if ($row === null) {
            throw new HttpError(503, 'installation_incomplete');
        }
        return $row;
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

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function view(array $row, bool $admin): array
    {
        $result = [
            'name' => (string) $row['name'],
            'official_url' => (string) $row['official_url'],
            'description' => (string) $row['description'],
            'logo_url' => (string) $row['logo_url'],
            'timezone' => (string) $row['timezone'],
            'theme' => [
                'mode' => (string) $row['theme_mode'],
                'colorPrimary' => (string) $row['theme_color'],
                'radius' => (string) $row['theme_radius'],
            ],
            'preferences' => [
                'layout' => (string) $row['layout_mode'],
                'sidebar' => ['collapsed' => (bool) $row['sidebar_collapsed']],
                'navigation' => ['styleType' => (string) $row['navigation_style'], 'split' => (bool) $row['navigation_split']],
                'breadcrumb' => ['enable' => (bool) $row['breadcrumb_enable'], 'showIcon' => (bool) $row['breadcrumb_show_icon'], 'styleType' => (string) $row['breadcrumb_style']],
                'tabbar' => ['enable' => (bool) $row['tabbar_enable'], 'styleType' => (string) $row['tabbar_style']],
                'footer' => ['enable' => (bool) $row['footer_enable'], 'fixed' => (bool) $row['footer_fixed']],
            ],
        ];
        if ($admin) {
            $result['version'] = (int) $row['version'];
            $result['updated_at'] = (int) $row['updated_at'];
        }
        return $result;
    }
}
