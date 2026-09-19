<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;

/** 检查可独立组合的包声明与应用结构，不连接外部服务。 */
final class ProjectStructureTest extends TestCase
{
    /** 项目展示名与 GitHub 地址统一，组件和模板的安装标识保持兼容。 */
    public function testProjectIdentityKeepsComponentNamesAndBuildEntryPointsStable(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = $this->json($root . '/composer.json');
        self::assertSame('zoujingli/typeapp', $composer['name']);
        self::assertSame('https://github.com/zoujingli/typeapp', $composer['homepage']);
        foreach (['distribution', 'template-distribution'] as $mappingName) {
            $mapping = $this->json($root . '/.github/' . $mappingName . '.json');
            self::assertSame('zoujingli/typeapp', $mapping['source-repository']);
        }
        $distribution = $this->json($root . '/.github/distribution.json');
        foreach ($distribution['packages'] as $name => $definition) {
            self::assertMatchesRegularExpression('/^type-[a-z0-9]+(?:-[a-z0-9]+)*$/D', $name);
            self::assertSame('zoujingli/' . $name, $definition['composer-name']);
            self::assertSame('zoujingli/' . $name, $definition['repository']);
            self::assertSame('public', $definition['visibility']);
        }
        $templateDistribution = $this->json($root . '/.github/template-distribution.json');
        self::assertSame('public', $templateDistribution['visibility']);
        $template = $this->json($root . '/templates/type-project/composer.json');
        self::assertSame('zoujingli/type-project', $template['name']);
        self::assertContains('type-app.json', $template['extra']['type-template']['paths']);
        foreach (['type-commands', 'type-migrations-core'] as $scenario) {
            $configuration = $this->json($root . '/docs/build-config/' . $scenario . '.json');
            self::assertSame([$composer['name']], $configuration['application']['enabled']);
        }
        self::assertStringContainsString('APP_NAME=TypeApp', (string) file_get_contents($root . '/.env.example'));
    }

    /** 业务由根 Composer 加载，开发工具与组件发行内容分别维护。 */
    public function testApplicationSourcesAreComposerMappedAndExcludedFromDistribution(): void
    {
        $root = dirname(__DIR__, 2);
        $composer = $this->json($root . '/composer.json');
        self::assertSame('app/', $composer['autoload']['psr-4']['app\\']);
        self::assertArrayNotHasKey('friendsofphp/php-cs-fixer', $composer['require']);
        self::assertArrayNotHasKey('phpunit/phpunit', $composer['require']);
        $distribution = $this->json($root . '/.github/distribution.json');
        $componentFiles = glob($root . '/plugin/type-*/composer.json');
        self::assertIsArray($componentFiles);
        $components = array_map(static fn (string $file): string => basename(dirname($file)), $componentFiles);
        $distributedComponents = array_keys($distribution['packages']);
        sort($components);
        sort($distributedComponents);
        self::assertSame($components, $distributedComponents);
        self::assertContains('type-mqtt', $distributedComponents);
        foreach ($distribution['packages'] as $name => $definition) {
            self::assertSame('plugin/' . $name, $definition['prefix']);
            $package = $this->json($root . '/' . $definition['prefix'] . '/composer.json');
            self::assertSame($package['name'], $definition['composer-name']);
            self::assertSame('1.0.x-dev', $package['extra']['branch-alias']['dev-main']);
        }
    }

    /** 迁移配置后所有已声明输入仍位于项目内，而输出仍归 build。 */
    public function testBuildScenariosUseAnExplicitRootOutsideTheDocumentDirectory(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame([], glob($root . '/type-*.json'));
        $files = glob($root . '/docs/build-config/type-*.json');
        self::assertGreaterThanOrEqual(53, count($files));
        foreach ($files as $file) {
            $configuration = $this->json($file);
            self::assertSame('../..', $configuration['project-root'], basename($file));
            self::assertStringStartsWith('build/', $configuration['output']);
            self::assertStringStartsWith('build/', $configuration['build-directory']);
            if (isset($configuration['entry'])) {
                self::assertFileExists($root . '/' . $configuration['entry']);
            }
            foreach ($configuration['sources'] ?? [] as $source) {
                self::assertTrue(is_file($root . '/' . $source) || is_dir($root . '/' . $source));
            }
        }
    }

    /** 格式器和测试框架仅进入开发依赖，不隐式升级固定 TypePHP。 */
    public function testDeveloperToolingDoesNotChangeLockedProductionDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = $this->json($root . '/composer.lock');
        $production = array_column($lock['packages'], 'name');
        foreach (['phpunit/phpunit', 'friendsofphp/php-cs-fixer', 'zoujingli/type-build', 'zoujingli/type-testing', 'phpstan/phpdoc-parser'] as $tool) {
            self::assertNotContains($tool, $production);
        }
        $development = array_column($lock['packages-dev'], null, 'name');
        self::assertSame('13.3.3', ltrim($development['phpunit/phpunit']['version'], 'v'));
        self::assertSame('3.95.25', ltrim($development['friendsofphp/php-cs-fixer']['version'], 'v'));
        self::assertSame('0.9.0', ltrim($development['swoole/typephp']['version'], 'v'));
        self::assertSame('2.9.0', ltrim($development['swoole/phpx']['version'], 'v'));
        self::assertSame('2.3.5', ltrim($development['phpstan/phpdoc-parser']['version'], 'v'));
    }

    /** @return array<string, mixed> 已检查的项目声明。 */
    private function json(string $file): array
    {
        $decoded = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
