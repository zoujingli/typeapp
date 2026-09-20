<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\AlarmService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\HttpError;
use Type\Core\Http\Identity;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\RequestBody;
use Type\Orm\Connection;
use Type\Orm\DatabaseManager;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;

/** 客户告警与站内通知入口；固定节点授权和租户范围由既有业务服务复核。 */
final class AlarmController
{
    /** 保存受管连接与消息工厂；告警及投递仍由既有服务拥有。 */
    public function __construct(private DatabaseManager $database, private Factory $messages)
    {
    }

    /** 当前规则及不可变版本历史共用有界查询和即时成员权限。 */
    #[Route('/customer/tenants/{tenant}/alarm-rules', name: 'customer.alarm_rules', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/alarm-rules/{rule}/versions', name: 'customer.alarm_rule_versions', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'rule' => '[a-f0-9]{32}'])]
    public function alarmRules(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $query = $this->alarmQuery($request, ['name' => Field::text()->length(0, 100), 'device_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'),
            'field' => Field::text()->matches('/^([a-zA-Z_][a-zA-Z0-9_]{0,63})?$/D')]);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(200, AlarmService::rules($this->connection($request), $this->identity($request), $tenantId, $query, $parameters['rule'] ?? ''));
    }

    /** 修改始终发布新版本；数值区间与冻结模型由服务在设备锁内校验。 */
    #[Route('/customer/tenants/{tenant}/alarm-rules', methods: ['POST'], name: 'customer.alarm_rule_create', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/alarm-rules/{rule}', methods: ['PATCH'], name: 'customer.alarm_rule_publish', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'rule' => '[a-f0-9]{32}'])]
    public function publishAlarmRule(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $ruleId = $parameters['rule'] ?? '';
        $fields = ['name' => Field::text()->required()->trim()->length(1, 100), 'lower' => Field::number()->nullable(), 'upper' => Field::number()->nullable(),
            'hysteresis' => Field::number(), 'enabled' => Field::boolean()];
        if ($ruleId === '') {
            $fields['device_id'] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
            $fields['field'] = Field::text()->required()->matches('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D');
        } else {
            $fields['version'] = Field::integer()->required()->range(1, 2147483646);
        }
        $data = $this->payload($request, $fields);
        return $this->response($ruleId === '' ? 201 : 200, ['data' => AlarmService::publish($this->connection($request), $this->identity($request), $tenantId, $data, $ruleId)]);
    }

    /** 告警保留当时规则、触发样本及结束原因；此入口不把确认当作恢复。 */
    #[Route('/customer/tenants/{tenant}/alarms', name: 'customer.alarms', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    #[Route('/customer/tenants/{tenant}/alarms/{alarm}', name: 'customer.alarm', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'alarm' => '[a-f0-9]{32}'])]
    public function alarms(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $query = $this->alarmQuery($request, ['device_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'), 'rule_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'),
            'status' => Field::text()->oneOf(['', 'active', 'ended']), 'from' => Field::integer()->cast()->range(1, 253402300799), 'to' => Field::integer()->cast()->range(1, 253402300799)]);
        $parameters = $request->getAttribute('type.route.params', []);
        $data = AlarmService::alarms($this->connection($request), $this->identity($request), $tenantId, $query, $parameters['alarm'] ?? '');
        return $this->response(200, isset($parameters['alarm']) ? ['data' => $data] : $data);
    }

    /** 人员确认只记录知悉；服务端验证当前操作权限，重复提交返回首次确认。 */
    #[Route('/customer/tenants/{tenant}/alarms/{alarm}/acknowledge', methods: ['POST'], name: 'customer.alarm_acknowledge', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'alarm' => '[a-f0-9]{32}'])]
    public function acknowledgeAlarm(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $this->payload($request, []);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(200, ['data' => AlarmService::acknowledge($this->connection($request), $this->identity($request), $tenantId, $parameters['alarm'])]);
    }

    /** 租户站内通知入口每次重新授权，活动与人工确认状态独立返回。 */
    #[Route('/customer/tenants/{tenant}/notifications', name: 'customer.notifications', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function notifications(ServerRequestInterface $request): ResponseInterface
    {
        $tenantId = $this->tenant($request);
        $filters = $this->alarmQuery($request, ['kind' => Field::text()->oneOf(['', 'triggered', 'ended'])]);
        return $this->response(200, \app\iot\service\NoticeService::notifications($this->connection($request), $this->identity($request), $tenantId, $filters));
    }

    /** 告警筛选拒绝未知参数，避免错误条件降级为全量列表。 */
    private function alarmQuery(ServerRequestInterface $request, array $fields): array
    {
        $raw = (new Input([]))->withQuery($request->getUri()->getQuery())->source('query');
        if (array_diff(array_keys($raw), [...array_keys($fields), 'page', 'per_page']) !== []) {
            throw new HttpError(422, 'alarm_filter_invalid');
        }
        return $this->query($request, $fields);
    }

    private function tenant(ServerRequestInterface $request): string
    {
        $headers = $request->getHeader('X-Tenant-Id');
        $parameters = $request->getAttribute('type.route.params', []);
        if (count($headers) !== 1 || !preg_match('/^[a-f0-9]{32}$/D', $headers[0]) || ($parameters['tenant'] ?? '') !== $headers[0]) {
            throw new HttpError(403, 'tenant_context_mismatch');
        }
        return $headers[0];
    }

    /** @param array<string, Field> $fields 有界对象白名单；拒绝伪造归属及状态。 */
    private function payload(ServerRequestInterface $request, array $fields): array
    {
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 4096), 4096, 4);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'unexpected_field');
        }
        return \_vali($fields, $input);
    }

    /** @param array<string, Field> $fields 额外筛选字段；每页最多100条。 */
    private function query(ServerRequestInterface $request, array $fields): array
    {
        $declarations = [];
        foreach ($fields + ['page' => Field::integer()->cast()->range(1, 100000), 'per_page' => Field::integer()->cast()->range(1, 100)] as $name => $field) {
            $declarations[$name] = $field->from('query');
        }
        $data = \_vali($declarations, (new Input([]))->withQuery($request->getUri()->getQuery()));
        return $data + ['page' => 1, 'per_page' => 20];
    }

    private function identity(ServerRequestInterface $request): Identity
    {
        foreach (['X-Support-Id', 'X-Impersonation-Id', 'X-Identity-Realm'] as $header) {
            if ($request->hasHeader($header)) {
                throw new HttpError(403, 'identity_context_invalid');
            }
        }
        $identity = $request->getAttribute('type.identity');
        if (!$identity instanceof Identity) {
            throw new HttpError(401, 'unauthenticated');
        }
        return $identity;
    }

    private function connection(ServerRequestInterface $request): Connection
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new \RuntimeException('告警接口需要受管请求作用域');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
