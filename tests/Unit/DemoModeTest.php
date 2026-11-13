<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use app\common\bootstrap\Settings;
use app\common\service\IdentityService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Config\Repository;
use Type\Core\Http\ActionHandler;
use Type\Core\Http\HttpError;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\ReadOnlyPolicy;

/**
 * 环境和已匹配路由的边界；真实身份与持久数据由 Tenant 管理入口复核。
 * @internal
 * @coversNothing
 */
final class DemoModeTest extends TestCase
{
    public function testEnvironmentIsAuthoritativeAndDebugRequiresDevEntry(): void
    {
        foreach (['dev', 'prod', 'demo'] as $environment) {
            $settings = new Repository(['app' => ['environment' => $environment, 'debug' => true]]);
            foreach ([false, true] as $entry) {
                self::assertSame($environment, Settings::environment($settings, $entry));
                self::assertSame($environment === 'dev' && $entry, Settings::debug($settings, $entry));
            }
        }
        self::assertFalse(Settings::configurationDefinitions()['APP_ENV']['editable']);
    }

    public function testLegacyAndUnknownEnvironmentsFailExplicitly(): void
    {
        foreach (['development', 'production', 'test', '', 'DEMO'] as $environment) {
            try {
                Settings::environment(new Repository(['app' => ['environment' => $environment]]), true);
                self::fail('非法环境被接受');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('dev、prod 或 demo', $error->getMessage());
            }
        }
    }

    public function testWriteRejectionPreventsHandlerSideEffects(): void
    {
        $factory = new Factory();
        $executed = 0;
        $handler = new ActionHandler(static function (ServerRequestInterface $request) use ($factory, &$executed): ResponseInterface {
            ++$executed;
            return $factory->createResponse(204);
        });
        $policy = new ReadOnlyPolicy(['platform.login', 'platform.logout']);
        foreach ([['POST', 'platform.site'], ['PUT', 'platform.login'], ['PATCH', 'platform.users'], ['DELETE', 'platform.users'], ['POST', 'platform.login.extra'], ['POST', '']] as [$method, $route]) {
            try {
                $policy->process($factory->createServerRequest($method, '/platform/auth/login')->withAttribute('type.route', $route), $handler);
                self::fail('写入未被拒绝');
            } catch (HttpError $error) {
                self::assertSame(403, $error->status());
                self::assertSame('demo_readonly', $error->errorCode());
            }
        }
        self::assertSame(0, $executed);
        foreach ([['GET', 'platform.site'], ['HEAD', 'platform.site'], ['OPTIONS', 'platform.site'], ['POST', 'platform.login'], ['POST', 'platform.logout']] as [$method, $route]) {
            self::assertSame(204, $policy->process($factory->createServerRequest($method, '/')->withAttribute('type.route', $route), $handler)->getStatusCode());
        }
        self::assertSame(5, $executed);
        try {
            (new ReadOnlyPolicy())->process($factory->createServerRequest('POST', '/')->withAttribute('type.route', 'platform.login'), $handler);
            self::fail('模板默认放行了认证写入');
        } catch (HttpError $error) {
            self::assertSame('demo_readonly', $error->errorCode());
        }
    }

    public function testOrdinaryAccountValidationAcceptsPlatformCredentials(): void
    {
        IdentityService::validateAccount('admin', '管理员', 'admin');
        IdentityService::validateAccount('ordinary', '普通账号', 'abcde');
        IdentityService::validateAccount('ordinary', '普通账号', str_repeat('a', 72));
        foreach (['', 'abcd', str_repeat('a', 73), "abc\0de"] as $password) {
            try {
                IdentityService::validateAccount('admin', '管理员', $password);
                self::fail('无效口令未被拒绝');
            } catch (HttpError $error) {
                self::assertSame('invalid_account', $error->errorCode());
            }
        }
    }
}
