<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use app\common\bootstrap\Settings;
use app\common\middleware\ApiErrors;
use PHPUnit\Framework\TestCase;
use Type\Build\ConfigCompiler;
use Type\Core\Config\Environment;
use Type\Core\Http\ActionHandler;
use Type\Core\Http\HttpControl;
use Type\Core\Http\Message\Factory;
use Type\Log\Channel;
use Type\Log\LogManager;
use Type\Log\Output;
use Type\Runtime\ExecutionScope;

/** 配置入口、生产诊断和探针的公开行为回归，不连接外部服务。 */
final class ArchitectureRuntimeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->directory = $root . '/build/architecture test-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        if (!class_exists(\app\generated\ProjectConfig::class)) {
            $generated = (new ConfigCompiler())->generate($root, ['class' => 'app\\generated\\ProjectConfig',
                'files' => ['config/app.php', 'config/database.php', 'config/cache.php']]);
            file_put_contents($this->directory . '/ProjectConfig.php', $generated['code']);
            require $this->directory . '/ProjectConfig.php';
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->directory);
    }

    /** 各入口一致拒绝，保存失败保持原文件，离线校验不隐式建库。 */
    public function testConfigurationPreflightAndSaveShareConsumerValidation(): void
    {
        $cases = [
            ['DB_SERVER_BUDGET' => 2, 'DB_ADMIN_RESERVE' => 10],
            ['DB_SERVER_BUDGET' => 12, 'DB_ADMIN_RESERVE' => 10],
            ['APP_ALLOWED_HOSTS' => 'https://invalid.example/path'],
            ['APP_TRUSTED_PROXIES' => 'not-a-cidr'],
            ['APP_UPLOAD_TEMP' => $this->directory . '/missing'],
            ['DB_POOL_CAPACITY' => 1, 'DB_POOL_IDLE' => 2],
            ['APP_HTTP_THREADS' => 3, 'APP_DATABASE_THREADS' => 2],
            ['APP_HTTP_MAX_REQUESTS' => 10, 'APP_HTTP_MAX_CONNECTIONS' => 2],
        ];
        foreach ($cases as $changes) {
            $contents = '';
            foreach ($changes as $key => $value) {
                $contents .= $key . "='" . $value . "'\n";
            }
            file_put_contents($this->directory . '/.env', $contents);
            $result = Settings::configurationCommand($this->directory, 'config:check', []);
            self::assertSame(2, $result['exit'], $contents);
            $settings = \app\generated\ProjectConfig::load(Environment::parse($contents, false));
            try {
                Settings::validateRuntimeConfiguration($settings, $this->directory, 'http');
                self::fail('启动预检接受了非法配置');
            } catch (\PHPUnit\Framework\AssertionFailedError $error) {
                throw $error;
            } catch (\Throwable) {
                self::assertTrue(true);
            }
            file_put_contents($this->directory . '/.env', "# preserve\n");
            try {
                Settings::configurationUpdate($this->directory, hash('sha256', "# preserve\n"), $changes);
                self::fail('管理保存接受了非法配置');
            } catch (\PHPUnit\Framework\AssertionFailedError $error) {
                throw $error;
            } catch (\Throwable) {
                self::assertSame("# preserve\n", file_get_contents($this->directory . '/.env'));
            }
        }
        file_put_contents($this->directory . '/.env', '');
        $valid = Settings::configurationCommand($this->directory, 'config:check', []);
        self::assertSame(0, $valid['exit']);
        self::assertLessThanOrEqual(90, $valid['budgets']['database']['maximum_application_connections']);
        self::assertDirectoryDoesNotExist($this->directory . '/build');
    }

    /** 数据库维护不依赖 HTTP 上传盘；旧缓存开关始终明确拒绝。 */
    public function testRoleBoundaryAndUnusedCacheAreExplicit(): void
    {
        $settings = \app\generated\ProjectConfig::load(Environment::parse('APP_UPLOAD_TEMP=/missing-test-upload', false));
        Settings::validateRuntimeConfiguration($settings, $this->directory, 'database');
        self::assertFalse(Settings::configurationDefinitions()['APP_CACHE_ENABLED']['editable']);
        $settings = \app\generated\ProjectConfig::load(Environment::parse('APP_CACHE_ENABLED=true', false));
        $this->expectExceptionMessage('feature_unavailable');
        Settings::validateRuntimeConfiguration($settings, $this->directory);
    }

    /** 未启用 Redis 的 profile 不验证无调用者的连接预算。 */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testUnselectedRedisDoesNotBlockConfigurationCheck(): void
    {
        require dirname(__DIR__) . '/fixtures/build-profile/identity.php';
        \Type\Generated\BuildIdentity::$profile = ['name' => 'sqlite', 'database' => 'sqlite', 'features' => ['web']];
        file_put_contents($this->directory . '/.env', "REDIS_SERVER_BUDGET=2\nREDIS_ADMIN_RESERVE=16\n");
        $result = Settings::configurationCommand($this->directory, 'config:check', []);
        self::assertSame(0, $result['exit']);
        self::assertNull($result['budgets']['redis']);
    }

    /** 生产 500 有一条可关联原因，调试只增加帧；两者都不包含秘密消息或参数。 */
    public function testProductionErrorsAreLoggedWithoutSecrets(): void
    {
        $messages = new Factory();
        foreach ([false, true] as $debug) {
            $stream = tmpfile();
            $output = Output::stream($stream, 8, 65536, 8192);
            $manager = new LogManager('test-build', ['app' => new Channel($output)]);
            $scope = new ExecutionScope();
            $scope->open($manager);
            $requestId = str_repeat('a', 32);
            $request = $messages->createServerRequest('GET', '/failure')->withAttribute('app.request_id', $requestId)
                ->withAttribute('app.logger', $manager->logger($scope, ['request_id' => $requestId]));
            $handler = new ActionHandler(static function (\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface {
                throw new \RuntimeException('sql-password-token-secret-canary');
            });
            $response = (new ApiErrors($messages, $debug, dirname(__DIR__, 2)))->process($request, $handler);
            self::assertSame(500, $response->getStatusCode());
            self::assertSame($requestId, $response->getHeaderLine('X-Request-Id'));
            $scope->close();
            self::assertSame(1, $output->stats()['accepted']);
            rewind($stream);
            $line = stream_get_contents($stream);
            self::assertStringContainsString('RuntimeException', $line);
            self::assertStringContainsString($requestId, $line);
            self::assertStringNotContainsString('sql-password-token-secret-canary', $line . (string) $response->getBody());
            self::assertSame($debug, str_contains($line, 'frames'));
            fclose($stream);
        }
    }

    /** 依赖就绪不改变存活；额度耗尽和排空时不再执行依赖探测。 */
    public function testReadinessComposesDependenciesWithCapacityAndStop(): void
    {
        $healthy = false;
        $checks = 0;
        $control = new HttpControl(1, 1, probes: true, readiness: static function () use (&$healthy, &$checks): bool {
            $checks++;
            return $healthy;
        });
        self::assertSame(503, $control->probe('/readyz')['status']);
        self::assertSame(200, $control->probe('/livez')['status']);
        self::assertSame(1, $checks);
        $healthy = true;
        self::assertSame(200, $control->probe('/readyz')['status']);
        $scope = $control->begin();
        self::assertSame(503, $control->probe('/readyz')['status']);
        self::assertSame(2, $checks);
        $scope->close();
        $control->finish($scope, false);
        $control->stop();
        self::assertSame(503, $control->probe('/readyz')['status']);
        self::assertSame(200, $control->probe('/livez')['status']);
        self::assertSame(2, $checks);
    }

    private function remove(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
