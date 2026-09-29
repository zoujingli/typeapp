<?php

declare(strict_types=1);

namespace TypeApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Build\BuildProfile;
use app\common\bootstrap\RuntimeCapabilities;
use RuntimeException;

final class BuildProfileTest extends TestCase
{
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
        foreach ([['pdo_mysql'], ['pdo_sqlite', 'redis'], ['pdo_sqlite', 'phar'], ['pdo_sqlite', 'pdo_mysql']] as $extensions) {
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
        putenv('TYPEAPP_BUILD_PROFILE');
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
