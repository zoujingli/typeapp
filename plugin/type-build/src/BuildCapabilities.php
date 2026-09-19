<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

final class BuildCapabilities
{
    public function collect(array $settings, array $packages): array
    {
        $declarations = [$settings['capabilities'] ?? []];
        foreach ($packages as $package) {
            $declarations[] = $package['extra']['type']['capabilities'] ?? [];
        }
        $capabilities = ['messages' => [], 'schema' => [], 'cache' => []];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || array_diff(array_keys($declaration), array_keys($capabilities)) !== []) {
                throw new RuntimeException('能力声明只接受 messages、schema 与 cache');
            }
            foreach ($declaration as $kind => $entries) {
                if (!is_array($entries) || count($entries) > 1000) {
                    throw new RuntimeException('能力声明必须是有界映射');
                }
                foreach ($entries as $name => $versions) {
                    if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_.:-]{0,127}$/D', $name) || !is_array($versions) || !array_is_list($versions) || $versions === [] || count($versions) > 100
                        || array_filter($versions, static fn (mixed $version): bool => !is_int($version) || $version < 1) !== []) {
                        throw new RuntimeException('能力名称或版本列表无效');
                    }
                    sort($versions);
                    $versions = array_values(array_unique($versions));
                    if (isset($capabilities[$kind][$name]) && $capabilities[$kind][$name] !== $versions) {
                        throw new RuntimeException('相同能力存在冲突版本声明：' . $name);
                    }
                    $capabilities[$kind][$name] = $versions;
                }
            }
        }
        foreach ($settings['queue']['jobs'] ?? [] as $job) {
            $capabilities['messages'][$job['type']][] = $job['version'];
        }
        foreach ($capabilities as &$entries) {
            ksort($entries);
            foreach ($entries as &$versions) {
                sort($versions);
                $versions = array_values(array_unique($versions));
            }
            unset($versions);
        }
        unset($entries);
        return $capabilities;
    }
}
