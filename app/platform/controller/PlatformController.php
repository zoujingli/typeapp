<?php

declare(strict_types=1);

namespace app\platform\controller;

use app\common\bootstrap\ApplicationContext;
use app\common\bootstrap\RuntimeCapabilityException;
use app\common\bootstrap\Settings;
use app\common\input\InputFields;
use app\common\service\AuditLog;
use app\common\service\RoleService;
use app\common\service\SiteSettings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Db;
use Type\Validate\Field;
use Type\Validate\Input;

/** 平台人员和角色的固定 HTTP 边界；客户端不能选择账号域或提交额外授权字段。 */
#[Group(prefix: '/platform', namePrefix: 'platform.', middleware: ['platform.auth'])]
final class PlatformController
{
    private string $basePath;

    /** 只保存响应工厂和启动根；业务模型使用当前请求作用域。 */
    public function __construct(private Factory $messages, ApplicationContext $context)
    {
        $this->basePath = $context->basePath;
    }

    /** 返回脱敏后的启动配置目录及当前权限；配置值来源和版本来自同一 .env 快照。 */
    #[Route('/configuration', name: 'configuration.read')]
    public function configuration(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $context = RoleService::readContext($identity, 'platform', '', 'config.read');
        try {
            $view = Settings::configurationView($this->basePath);
        } catch (\InvalidArgumentException|\RuntimeException) {
            throw new HttpError(503, 'configuration_invalid');
        }
        return $this->response($view + ['permissions' => $context['permissions'], 'catalog' => RoleService::catalog('platform'), 'menus' => RoleService::menus('platform', $context['permissions'])]);
    }

    /** 读取站点业务设置；与运行时配置分开，避免把品牌信息误当作进程配置。 */
    #[Route('/site', name: 'site.read')]
    public function site(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $context = RoleService::readContext($identity, 'platform', '', 'site.read');
        return $this->response(SiteSettings::managementView() + ['permissions' => $context['permissions'], 'catalog' => RoleService::catalog('platform'), 'menus' => RoleService::menus('platform', $context['permissions'])]);
    }

    /** 站点设置采用固定字段、版本号和授权事务，失败时不覆盖上一份品牌配置。 */
    #[Route('/site', methods: ['PUT'], name: 'site.manage', middleware: ['body.json'])]
    public function updateSite(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        $input = InputFields::body($request, ['version' => Field::integer()->required()->range(1, PHP_INT_MAX), 'changes' => new Field('object')], 'site_settings_input_invalid');
        $version = $input['version'];
        $changes = $input['changes'] instanceof \stdClass ? get_object_vars($input['changes']) : $input['changes'];
        $token = substr($request->getHeaderLine('Authorization'), 7);
        $result = RoleService::authorized($identity, $token, 'platform.site.manage', function (Identity $current, array $permissions) use ($version, $changes): array {
            $transaction = Db::connection('default', true);
            $result = SiteSettings::update($version, $changes);
            AuditLog::append($transaction, null, $current, 'platform.site.update', 'site_settings', 'success', [
                'changed_fields' => implode(',', $result['changed']), 'version' => $result['version'],
            ], 'platform');
            return $result + ['permissions' => $permissions, 'catalog' => RoleService::catalog('platform'), 'menus' => RoleService::menus('platform', $permissions)];
        }, 'platform', 'platform');
        return $this->response($result);
    }

    /** 配置写入复用现有授权锁、文件锁和原子替换；成功后仅报告待重启，不伪装当前进程已变更。 */
    #[Route('/configuration', methods: ['PUT'], name: 'configuration.manage')]
    public function updateConfiguration(ServerRequestInterface $request): ResponseInterface
    {
        $identity = $this->identity($request);
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 32768), 32768, 12);
        $body = $input->source('body');
        $changes = $body['changes'] ?? null;
        if ($changes instanceof \stdClass) {
            $changes = get_object_vars($changes);
        }
        if (!is_array($body) || array_diff(array_keys($body), ['version', 'changes']) !== []
            || !is_string($body['version'] ?? null) || !is_array($changes)) {
            throw new HttpError(422, 'configuration_input_invalid');
        }
        $token = substr($request->getHeaderLine('Authorization'), 7);
        $basePath = $this->basePath;
        try {
            $result = RoleService::authorized($identity, $token, 'platform.config.manage', function (Identity $current, array $permissions) use ($body, $changes, $basePath): array {
                $transaction = Db::connection('default', true);
                $result = Settings::configurationUpdate($basePath, (string)$body['version'], $changes);
                AuditLog::append($transaction, null, $current, 'platform.configuration.update', 'configuration', 'success', [
                    'changed_fields' => implode(',', $result['changed']), 'version' => $result['version'],
                ], 'platform');
                return $result;
            }, 'platform', 'platform');
        } catch (RuntimeCapabilityException $error) {
            // 交由统一 HTTP 边界输出稳定能力错误码，不将 CLI 诊断说明作为接口错误码。
            throw $error;
        } catch (\InvalidArgumentException $error) {
            throw new HttpError(422, $error->getMessage());
        } catch (\RuntimeException $error) {
            if ($error->getMessage() === 'config_version_conflict') {
                throw new HttpError(409, 'stale_version');
            }
            throw new HttpError(503, 'configuration_write_failed');
        }
        return $this->response($result);
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        foreach (['X-Tenant-Id', 'X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_context_invalid');
            }
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity || !in_array('realm:platform', $identity->roles(), true)) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function response(array $data): ResponseInterface
    {
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode(['data' => $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
