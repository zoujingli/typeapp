<?php

declare(strict_types=1);

namespace TypeApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\BuildProfile;
use app\common\bootstrap\RuntimeCapabilities;
use RuntimeException;

final class BuildProfileTest extends TestCase
{
    private string|false $originalProfile;

    /** 每个用例显式隔离构建选择，不能继承运行 PHPUnit 的发布 profile。 */
    protected function setUp(): void
    {
        $this->originalProfile = getenv('TYPEAPP_BUILD_PROFILE');
        self::assertTrue(putenv('TYPEAPP_BUILD_PROFILE='));
        self::assertContains(getenv('TYPEAPP_BUILD_PROFILE'), [false, '']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProfiles')]
    public function testRejectsInvalidConfiguration(array $settings): void
    {
        $this->expectException(RuntimeException::class);
        BuildProfile::resolve($settings);
    }

    public static function invalidProfiles(): array
    {
        return [
            [['build-profiles' => []]],
            [['build-profiles' => ['bad' => ['database' => 'oracle', 'features' => ['web']]]]],
            [['build-profiles' => ['bad' => ['database' => 'mysql', 'features' => ['phar']]]]],
            [['build-profile' => 'missing']],
        ];
    }

    public function testDetectsLeakedAndMissingRuntimeExtensions(): void
    {
        $profile = ['name' => 'small', 'database' => 'sqlite', 'features' => ['web']];
        BuildProfile::assertExtensions(['pdo', 'pdo_sqlite', 'swoole'], $profile);
        foreach ([['pdo_mysql'], ['pdo_sqlite', 'redis'], ['pdo_sqlite', 'phar'], ['pdo_sqlite', 'pdo_mysql'],
            ['pdo_sqlite', 'sqlite3'], ['pdo_sqlite', 'session'], ['pdo_sqlite', 'tokenizer'],
            ['pdo_sqlite', 'mysqli'], ['pdo_sqlite', 'pgsql']] as $extensions) {
            try {
                BuildProfile::assertExtensions($extensions, $profile);
                self::fail('Invalid extension set accepted');
            } catch (RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    protected function tearDown(): void
    {
        // Swoole 的线程安全 putenv 钩子使用 CRT；Windows 删除变量须传入空值，
        // 仅传名称可能保留前一用例的值。空值与未设置在构建选择中语义相同。
        $setting = $this->originalProfile === false && PHP_OS_FAMILY !== 'Windows'
            ? 'TYPEAPP_BUILD_PROFILE' : 'TYPEAPP_BUILD_PROFILE=' . ($this->originalProfile ?: '');
        self::assertTrue(putenv($setting));
        self::assertSame($this->originalProfile ?: '', getenv('TYPEAPP_BUILD_PROFILE') ?: '');
    }

    public function testResolvesDatabaseAndImplicitRedisClosure(): void
    {
        putenv('TYPEAPP_BUILD_PROFILE=mysql');
        $resolved = BuildProfile::resolve(['build-profiles' => [
            'sqlite' => ['database' => 'sqlite', 'features' => ['web']],
            'mysql' => ['database' => 'mysql', 'features' => ['web', 'queue']],
        ]]);
        self::assertSame('mysql', $resolved['selected']);
        self::assertSame(['queue', 'redis', 'web'], $resolved['profiles']['mysql']['features']);
        self::assertSame(['pdo_mysql', 'redis'], BuildProfile::runtimeExtensions(['pdo_mysql', 'pdo_pgsql', 'redis'], BuildProfile::selected($resolved)));
    }

    public function testRejectsUnknownProfileAndDatabase(): void
    {
        putenv('TYPEAPP_BUILD_PROFILE=oracle');
        $this->expectException(RuntimeException::class);
        BuildProfile::resolve(['build-profiles' => ['sqlite' => ['database' => 'sqlite', 'features' => ['web']]]]);
    }

    public function testDevelopmentProfileEnvironmentDoesNotPretendToBeVerifiedRuntimeIdentity(): void
    {
        putenv('TYPEAPP_BUILD_PROFILE=mysql');
        self::assertSame(['name' => null, 'database' => null, 'features' => [], 'verified' => false], RuntimeCapabilities::profile());
        RuntimeCapabilities::assertDatabase('sqlite');
        RuntimeCapabilities::requireFeature('web');
    }

    public function testRejectedCapabilitiesDescribeUnselectedDatabaseAndRedis(): void
    {
        $resolved = BuildProfile::resolve(['build-profiles' => [
            'sqlite' => ['database' => 'sqlite', 'features' => ['web']],
        ]]);
        self::assertSame(['alerts', 'cache', 'database:mysql', 'database:pgsql', 'dom', 'exports', 'intl', 'iot', 'mqtt', 'queue', 'redis', 'scheduler', 'xml', 'zip'], BuildProfile::rejectedCapabilities(BuildProfile::selected($resolved)));
    }

    public function testTargetModuleStartupKeepsSelectedDriverAndRejectsUnaccountedDependencies(): void
    {
        $source = "    ZEND_MOD_REQUIRED(\"pdo\")\n    ZEND_MOD_REQUIRED(\"pdo_mysql\")\n    ZEND_MOD_REQUIRED(\"redis\")\n";
        self::assertSame("    ZEND_MOD_REQUIRED(\"pdo\")\n", BuildProfile::targetModuleDependencies($source, ['pdo', 'pdo_sqlite']));
        self::assertSame($source, BuildProfile::targetModuleDependencies($source, ['pdo', 'pdo_mysql', 'redis']));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未提供的扩展');
        BuildProfile::targetModuleDependencies("    ZEND_MOD_REQUIRED(\"intl\")\n", ['pdo', 'pdo_sqlite']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testGeneratedIdentityEnforcesCapabilitiesAndAllowsUnprofiledDevelopment(): void
    {
        require dirname(__DIR__) . '/fixtures/build-profile/identity.php';
        \Type\Generated\BuildIdentity::$profile = ['name' => null, 'database' => null, 'features' => []];
        self::assertFalse(RuntimeCapabilities::profile()['verified']);
        RuntimeCapabilities::requireFeature('redis');
        \Type\Generated\BuildIdentity::$profile = ['name' => 'sqlite', 'database' => 'sqlite', 'features' => ['web']];
        self::assertTrue(RuntimeCapabilities::profile()['verified']);
        RuntimeCapabilities::assertDatabase('sqlite');
        RuntimeCapabilities::requireFeature('web');
        foreach (['mysql', 'pgsql'] as $database) {
            try {
                RuntimeCapabilities::assertDatabase($database);
                self::fail('Database mismatch accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringStartsWith('runtime_profile_database_mismatch:', $error->getMessage());
            }
        }
        self::assertFalse(RuntimeCapabilities::hasFeature('redis'));
        $this->expectExceptionMessage('feature_unavailable: redis');
        RuntimeCapabilities::requireFeature('redis');
    }

    /** 关闭的功能通过 HTTP 仍返回稳定错误，不能被内部错误脱敏分支吞掉。 */
    public function testUnavailableCapabilityRemainsExplicitInHttpResponses(): void
    {
        $messages = new \Type\Core\Http\Message\Factory();
        $handler = new class () implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                throw new \app\common\bootstrap\RuntimeCapabilityException('feature_unavailable', 'exports');
            }
        };
        $response = (new \app\common\middleware\ApiErrors($messages))->process($messages->createServerRequest('GET', '/exports'), $handler);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('feature_unavailable', json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR)['error']);
    }

    /** 进程提供数据库配置时，保存无关字段仍校验实际 profile；不能把缺省 SQLite 当作待运行驱动。 */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\DataProvider('externalDatabaseProfiles')]
    public function testConfigurationUpdateUsesEffectiveProfileAndProtectsProcessOverrides(string $driver): void
    {
        require dirname(__DIR__) . '/fixtures/build-profile/identity.php';
        \Type\Generated\BuildIdentity::$profile = ['name' => $driver, 'database' => $driver, 'features' => ['web']];
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/profile-settings-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $configuration = json_decode(file_get_contents($root . '/docs/build-config/type-app.json'), true, 64, JSON_THROW_ON_ERROR);
        $generated = (new \Type\Build\ConfigCompiler())->generate($root, $configuration['config']);
        file_put_contents($directory . '/ProjectConfig.php', $generated['code']);
        require $directory . '/ProjectConfig.php';
        $original = ['DB_DRIVER' => getenv('DB_DRIVER'), 'APP_NAME' => getenv('APP_NAME')];
        putenv('DB_DRIVER=' . $driver);
        putenv(PHP_OS_FAMILY === 'Windows' ? 'APP_NAME=' : 'APP_NAME');
        try {
            file_put_contents($directory . '/.env', '');
            $view = \app\common\bootstrap\Settings::configurationView($directory);
            $saved = \app\common\bootstrap\Settings::configurationUpdate($directory, $view['version'], ['APP_NAME' => 'profile-settings']);
            self::assertTrue($saved['restart_required']);
            self::assertSame($driver, \app\common\bootstrap\Settings::load($directory)->text('database.driver'));
            self::assertStringNotContainsString('DB_DRIVER', file_get_contents($directory . '/.env'));
            try {
                \app\common\bootstrap\Settings::configurationUpdate($directory, $saved['version'], ['DB_DRIVER' => 'sqlite']);
                self::fail('管理端覆盖了进程只读字段');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('configuration_field_read_only', $error->getMessage());
            }
            putenv(PHP_OS_FAMILY === 'Windows' ? 'DB_DRIVER=' : 'DB_DRIVER');
            // Windows 空值删除与 Unix unset 采用各自平台语义；原生任务也运行本用例。
            $before = file_get_contents($directory . '/.env');
            try {
                \app\common\bootstrap\Settings::configurationUpdate($directory, $saved['version'], ['DB_DRIVER' => 'sqlite']);
                self::fail('保存了与程序不匹配的数据库');
            } catch (\app\common\bootstrap\RuntimeCapabilityException $error) {
                self::assertSame('runtime_profile_database_mismatch', $error->errorCode());
            }
            self::assertSame($before, file_get_contents($directory . '/.env'));
        } finally {
            foreach ($original as $key => $value) {
                putenv($value === false && PHP_OS_FAMILY !== 'Windows' ? $key : $key . '=' . ($value ?: ''));
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    /** @return list<array{string}> 两种外部数据库必须维持相同的配置覆盖语义。 */
    public static function externalDatabaseProfiles(): array
    {
        return [['mysql'], ['pgsql']];
    }

    public function testSdkProfileResolutionSupportsSpacesAndDifferentWorkingDirectories(): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/build/profile config ' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $configuration = $directory . '/type-app.json';
        file_put_contents($configuration, json_encode(['build-profiles' => ['sqlite' => ['database' => 'sqlite', 'features' => ['web']]]], JSON_THROW_ON_ERROR));
        try {
            $environment = array_replace(getenv(), ['TYPEAPP_BUILD_PROFILE' => '', 'TYPEAPP_BUILD_CONFIGURATION' => $configuration, 'TYPEAPP_BUILD_FEATURES' => 'web']);
            $command = [PHP_BINARY, $root . '/tools/build-profile.php', 'sqlite'];
            $result = (new \Type\Testing\Process($command, $directory, $environment))->wait(10);
            self::assertTrue($result->successful(), $result->stderr);
            self::assertSame("web\n", $result->stdout);
            $environment['TYPEAPP_BUILD_FEATURES'] = 'redis,web';
            $result = (new \Type\Testing\Process($command, $directory, $environment))->wait(10);
            self::assertSame(1, $result->exitCode);
            self::assertStringContainsString('功能闭包冲突', $result->stderr);
            $environment['TYPEAPP_BUILD_FEATURES'] = '';
            $result = (new \Type\Testing\Process([PHP_BINARY, $root . '/tools/build-profile.php', 'mysql'], $directory, $environment))->wait(10);
            self::assertSame(1, $result->exitCode);
            self::assertStringContainsString('未知 SDK profile', $result->stderr);
        } finally {
            unlink($configuration);
            rmdir($directory);
        }
    }
}
