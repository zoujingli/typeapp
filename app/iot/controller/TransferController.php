<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\TransferService;
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

/** 客户双方的转移入口；平台资产编辑不提供归属审批或直接切换旁路。 */
final class TransferController
{
    /** 保存受管连接与消息工厂；持久流程仍由TransferService拥有。 */
    public function __construct(private DatabaseManager $database, private Factory $messages)
    {
    }

    /** 查询本方发出或收到的邀请；发起时固定准确设备版本和原请求身份。 */
    #[Route('/customer/tenants/{tenant}/transfers', methods: ['GET', 'POST'], name: 'customer.transfers', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function transfers(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        if ($request->getMethod() === 'GET') {
            $input = (new Input([]))->withQuery($request->getUri()->getQuery());
            $fields = ['direction' => Field::text()->oneOf(['source', 'target'])->defaultValue('source'),
                'status' => Field::text()->oneOf(['', 'requested', 'rejected', 'cancelled', 'frozen', 'isolating', 'activating', 'completed']),
                'device_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'),
                'page' => Field::integer()->cast()->range(1, 100000), 'per_page' => Field::integer()->cast()->range(1, 100)];
            if (array_diff(array_keys($input->source('query')), array_keys($fields)) !== []) {
                throw new HttpError(422, 'transfer_filter_invalid');
            }
            $declarations = [];
            foreach ($fields as $name => $field) {
                $declarations[$name] = $field->from('query');
            }
            $query = \_vali($declarations, $input) + ['page' => 1, 'per_page' => 20, 'status' => '', 'device_id' => ''];
            return $this->response(200, TransferService::listing($connection, $identity, $tenant, $query['page'], $query['per_page'], $query['direction'], $query['status'], $query['device_id']));
        }
        $fields = ['version' => Field::integer()->required()->range(1, 2147483645)];
        foreach (['transfer_id', 'device_id', 'target_tenant_id'] as $field) {
            $fields[$field] = Field::text()->required()->matches('/^[a-f0-9]{32}$/D');
        }
        $data = $this->payload($request, $fields);
        return $this->response(202, ['data' => TransferService::request($connection, $identity, $tenant, $data['device_id'], $data['target_tenant_id'], $data['transfer_id'], $data['version'])]);
    }

    /** 明确审批、取消、重发或推进；202保持实际阶段，不代表设备完成。 */
    #[Route('/customer/tenants/{tenant}/transfers/{transfer}', methods: ['GET', 'POST'], name: 'customer.transfer', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'transfer' => '[a-f0-9]{32}'])]
    public function transfer(ServerRequestInterface $request): ResponseInterface
    {
        $tenant = $this->tenant($request);
        $connection = $this->connection($request);
        $identity = $this->identity($request);
        $parameters = $request->getAttribute('type.route.params', []);
        $id = $parameters['transfer'];
        if ($request->getMethod() === 'GET') {
            return $this->response(200, ['data' => TransferService::detail($connection, $identity, $tenant, $id)]);
        }
        $data = $this->payload($request, ['action' => Field::text()->required()->oneOf(['accept', 'reject', 'cancel', 'retry', 'switch']),
            'version' => Field::integer()->required()->range(1, 2147483645),
            'switch_id' => Field::text()->matches('/^[a-f0-9]{32}$/D'), 'decision_id' => Field::text()->matches('/^[a-f0-9]{32}$/D'),
            'target_product_id' => Field::text()->matches('/^[a-f0-9]{32}$/D'), 'target_model_version' => Field::integer()->range(1, 2147483646),
            'copy_name' => Field::text()->trim()->length(1, 100)]);
        if ($data['action'] === 'switch') {
            if (count($data) !== 3 || !isset($data['switch_id'])) {
                throw new HttpError(422, 'transfer_switch_invalid');
            }
            return $this->response(202, ['data' => TransferService::advance($connection, $identity, $tenant, $id, $data['switch_id'], $data['version'])]);
        }
        if ($data['action'] === 'retry') {
            if (count($data) !== 2) {
                throw new HttpError(422, 'transfer_decision_invalid');
            }
            return $this->response(202, ['data' => TransferService::retry($connection, $identity, $tenant, $id, $data['version'])]);
        }
        return $this->response(202, ['data' => TransferService::decide($connection, $identity, $tenant, $id, $data)]);
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
            throw new \RuntimeException('转移接口需要受管请求作用域');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
