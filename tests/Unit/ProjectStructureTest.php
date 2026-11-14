<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use TypeTests\Support\ModelFirstBoundary;

require_once dirname(__DIR__) . '/Support/ModelFirstBoundary.php';

/**
 * 检查可独立组合的包声明与应用结构，不连接外部服务。
 * @internal
 * @coversNothing
 */
final class ProjectStructureTest extends TestCase
{
    /**
     * 项目展示名与 GitHub 地址统一，组件和模板的安装标识保持兼容。
     */
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
        self::assertStringContainsString('APP_NAME=TypeApp', (string)file_get_contents($root . '/.env.example'));
    }

    /**
     * 业务由根 Composer 加载，开发工具与组件发行内容分别维护。
     */
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

    /**
     * 迁移配置后所有已声明输入仍位于项目内，而输出仍归 build。
     */
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

    /**
     * 格式器和测试框架仅进入开发依赖，不隐式升级固定 TypePHP。
     */
    public function testDeveloperToolingDoesNotChangeLockedProductionDependencies(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = $this->json($root . '/composer.lock');
        $production = array_column($lock['packages'], 'name');
        foreach (['phpunit/phpunit', 'friendsofphp/php-cs-fixer', 'zoujingli/type-build', 'zoujingli/type-testing', 'phpstan/phpdoc-parser'] as $tool) {
            self::assertNotContains($tool, $production);
        }
        $development = array_column($lock['packages-dev'], null, 'name');
        self::assertSame('13.4.6', ltrim($development['phpunit/phpunit']['version'], 'v'));
        self::assertSame('3.95.30', ltrim($development['friendsofphp/php-cs-fixer']['version'], 'v'));
        $toolchain = $this->json($root . '/toolchain.lock.json');
        foreach (['typephp', 'phpx'] as $component) {
            self::assertSame($toolchain[$component]['version'], ltrim($development['swoole/' . $component]['version'], 'v'));
            self::assertSame($toolchain[$component]['reference'], $development['swoole/' . $component]['source']['reference']);
        }
        $build = $this->json($root . '/plugin/type-build/composer.json');
        self::assertArrayNotHasKey('phpstan/phpdoc-parser', $build['require']);
        self::assertArrayHasKey('nikic/php-parser', $build['require']);
        self::assertArrayHasKey('nikic/php-parser', $development);
    }

    /**
     * 已迁移切片以 Model 访问实体；底层访问及基础设施签名采用可失效的精确登记。
     */
    public function testModelFirstSlicesDoNotReintroduceUnscopedEntityQueries(): void
    {
        $root = dirname(__DIR__, 2);
        $policy = $this->json($root . '/tests/fixtures/model-first-boundary.json');
        self::assertSame([
            'app/common/service/IdentityService.php',
            'app/common/service/RoleService.php',
            'app/common/service/MenuService.php',
            'app/common/service/SiteSettings.php',
            'app/platform/service/TenantService.php',
            'app/platform/service/ImpersonationService.php',
            'app/center/service/EnrollmentService.php',
            'app/center/service/ProductService.php',
            'app/center/service/OperationsService.php',
            'app/common/middleware/TenantContext.php',
            'app/common/controller/AuthController.php',
            'app/common/controller/IdentityController.php',
            'app/platform/controller/PlatformController.php',
            'app/platform/controller/TenantController.php',
            'app/platform/controller/MenuController.php',
            'app/platform/controller/ImpersonationController.php',
            'app/system/controller/SystemController.php',
            'app/center/controller/ProductController.php',
            'app/center/service/ApplicationGrantService.php',
            'app/center/service/ClientApplicationService.php',
            'app/center/service/DeviceService.php',
            'app/center/service/RecoveryService.php',
            'app/center/service/TransferService.php',
            'app/center/service/DeviceAccess.php',
        ], $policy['files']);
        $sources = [];
        foreach ($policy['files'] as $file) {
            $sources[$file] = (string)file_get_contents($root . '/' . $file);
        }
        $violations = (new ModelFirstBoundary())->violations($sources, $policy['exceptions']);
        self::assertSame([], $violations, implode("\n", $violations));
    }

    /**
     * 权限是平台菜单目录的稳定值；角色通过受管关系绑定，System 中间表保留 Tenant 范围。
     */
    public function testRolePermissionValuesRemainControlledByTheAuthorizationService(): void
    {
        $root = dirname(__DIR__, 2);
        $schema = (string)file_get_contents($root . '/database/migrations/020_platform_system.php');
        self::assertStringContainsString("primary(['role_id', 'permission'])", $schema);

        $models = (new \Type\Build\ModelCompiler())->compile([$root . '/app/platform/model', $root . '/app/system/model', $root . '/app/center/model'])['models'];
        $models = array_column($models, null, 'class');
        $platform = $models['app\platform\model\PlatformRole']['relations']['menus'];
        $system = $models['app\system\model\SystemRole']['relations']['menus'];
        self::assertSame('platform_role_permission', $platform['table']);
        self::assertSame('app\platform\model\PlatformMenu', $platform['target']);
        self::assertSame('permission', $platform['targetKey']);
        self::assertSame('system_role_permission', $system['table']);
        self::assertSame('app\system\model\SystemMenu', $system['target']);
        self::assertSame('tenant_id', $system['pivotTenant']);
        self::assertSame('permission', $system['targetKey']);
        $directory = $models['app\platform\model\ApplicationDirectory'];
        self::assertSame('center_application', $directory['table']);
        self::assertNull($directory['tenant']);
        $deviceDirectory = $models['app\platform\model\DeviceDirectory'];
        self::assertSame('center_device', $deviceDirectory['table']);
        self::assertNull($deviceDirectory['tenant']);
        self::assertFalse($deviceDirectory['fields']['name']['fillable']);
        $enrollmentDirectory = $models['app\platform\model\EnrollmentDirectory'];
        self::assertSame('center_device_enrollment', $enrollmentDirectory['table']);
        self::assertNull($enrollmentDirectory['tenant']);

        $documentation = (string)file_get_contents($root . '/docs/development/iot-identity.md');
        self::assertStringContainsString('角色与菜单的关联由 `RoleService`', $documentation);
        self::assertStringContainsString('客户不能修改菜单目录', $documentation);
    }

    /**
     * 运行观察投影以 Model 暴露字段，只有槽位/CAS 写入留在模型内部。
     */
    public function testRuntimeMetricUsesTheCenterModelBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $models = (new \Type\Build\ModelCompiler())->compile([$root . '/app/center/model'])['models'];
        $models = array_column($models, null, 'class');
        $metric = $models['app\center\model\CenterRuntimeMetric'];
        self::assertSame('center_runtime_metric', $metric['table']);
        self::assertSame('slot', $metric['primary']);
        self::assertNull($metric['tenant']);
        self::assertSame('integer', $metric['fields']['slot']['type']);
        self::assertFalse($metric['fields']['metrics_json']['visible']);
        self::assertTrue($metric['fields']['previous_json']['nullable']);
        self::assertStringContainsString('CenterRuntimeMetric::recordObservation', (string)file_get_contents($root . '/docs/guide/database.md'));
    }

    /**
     * Center 设备接入事实直接绑定 Tenant，跨客户约束不能只依赖父记录推断。
     */
    public function testCenterAccessFactsDeclareTenantScope(): void
    {
        $root = dirname(__DIR__, 2);
        $models = (new \Type\Build\ModelCompiler())->compile([$root . '/app/center/model'])['models'];
        $models = array_column($models, null, 'class');
        foreach (['DeviceCredential', 'DeviceConnection', 'DeviceEnrollmentBinding'] as $name) {
            $model = $models['app\\center\\model\\' . $name];
            self::assertSame('tenant_id', $model['tenant']);
            self::assertArrayHasKey('tenant_id', $model['fields']);
            self::assertSame('string', $model['fields']['tenant_id']['type']);
        }
        $schema = (string)file_get_contents($root . '/database/migrations/040_center_core.php');
        self::assertStringContainsString('return new class extends SchemaMigration', $schema);
        self::assertStringNotContainsString('function version()', $schema);
        self::assertStringContainsString("\$table->string('tenant_id', 32)", $schema);
        self::assertStringNotContainsString("foreign(['tenant_id', 'device_id'], 'center_device'", $schema);
        self::assertGreaterThanOrEqual(1, substr_count($schema, "foreign('device_id', 'center_device', 'id')"));
        self::assertStringContainsString("foreign(['tenant_id', 'ownership_id'], 'center_device_ownership', ['tenant_id', 'id'])", $schema);
        self::assertStringContainsString("foreign(['tenant_id', 'enrollment_id'], 'center_device_enrollment', ['tenant_id', 'id'])", $schema);
        $plan = \Type\Orm\Migration\MigrationFiles::load($root . '/database/migrations');
        self::assertSame(['020_platform_system', '026_broker_resource_observations', '028_broker_access_policy', '029_platform_mqtt_access_certificate',
            '030_broker_access_certificate_overlap', '031_broker_access_crl', '032_platform_mqtt_connection_operation', '032_platform_mqtt_quota_revision',
            '033_platform_mqtt_runtime_revision', '034_platform_mqtt_debug_credential', '035_platform_mqtt_debug_occupancy', '036_platform_mqtt_compatibility',
            '037_platform_mqtt_recovery', '040_center_core'], array_map(static fn (\Type\Orm\Migration\SchemaMigration $migration): string => $migration->version(), $plan));
    }

    /**
     * 恢复主状态使用单主键 Model；复合主体表保持受控查询边界。
     */
    public function testRecoveryStateUsesTheCenterModelBoundary(): void
    {
        $root = dirname(__DIR__, 2);
        $compiled = (new \Type\Build\ModelCompiler())->compile([$root . '/app/center/model']);
        $models = $compiled['models'];
        $models = array_column($models, null, 'class');
        $recovery = $models['app\center\model\CenterRecovery'];
        self::assertSame('center_recovery', $recovery['table']);
        self::assertSame('id', $recovery['primary']);
        self::assertNull($recovery['tenant']);
        self::assertArrayHasKey('state', $recovery['fields']);
        self::assertArrayNotHasKey('column', $recovery['fields']['state']);
        self::assertStringContainsString('getState()', $compiled['code']);
        self::assertTrue($recovery['fields']['slot']['nullable']);
        $service = (string)file_get_contents($root . '/app/center/service/RecoveryService.php');
        self::assertStringContainsString('CenterRecovery::query()', $service);
        self::assertStringContainsString('center_recovery_subject', $service);
        self::assertStringContainsString("'credential' => ['id', 'tenant_id', 'device_id'", $service);
        self::assertStringContainsString('复合主键', (string)file_get_contents($root . '/docs/guide/database.md'));
    }

    /**
     * @return array<string, mixed> 已检查的项目声明。
     */
    private function json(string $file): array
    {
        $decoded = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }
}
