<?php

declare(strict_types=1);

namespace app\iot\controller;

use app\iot\service\ExportService;
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

/** 客户历史导出的严格HTTP入口；私有文件只经当前身份校验后的受管流发送。 */
final class ExportController
{
    /** 注入既有文件生命周期所有者、受管连接和消息工厂。 */
    public function __construct(private DatabaseManager $database, private Factory $messages, private ExportService $exports)
    {
    }

    /** 创建包含全部当前匹配行的任务；分页、游标和其他未声明字段不能改变导出。 */
    #[Route('/customer/tenants/{tenant}/devices/{device}/exports', methods: ['POST'], name: 'customer.export-create', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'device' => '[a-f0-9]{32}'])]
    public function createExport(ServerRequestInterface $request): ResponseInterface
    {
        $data = $this->payload($request, ['id' => Field::text()->required()->matches('/^[a-f0-9]{32}$/D'), 'kind' => Field::text()->required()->oneOf(['records', 'minutes']),
            'timezone' => Field::text()->required()->length(1, 100), 'from' => Field::integer()->required()->range(1, 253402300799),
            'to' => Field::integer()->required()->range(1, 253402300799),
            'product_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'), 'ownership_id' => Field::text()->matches('/^([a-f0-9]{32})?$/D'),
            'model_version' => Field::integer()->range(1, 2147483646), 'field' => Field::text()->matches('/^([a-zA-Z_][a-zA-Z0-9_]{0,63})?$/D'),
            'sort' => Field::text()->oneOf(['sampled_desc', 'sampled_asc', 'received_desc', 'received_asc'])]);
        $id = $data['id'];
        $kind = $data['kind'];
        $timezone = $data['timezone'];
        unset($data['id'], $data['kind'], $data['timezone']);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(202, ['data' => $this->exports->create($this->connection($request), $this->identity($request), $this->tenant($request), $parameters['device'], $kind, $data, $timezone, $id)]);
    }

    /** 任务列表每次重验当前角色；浏览器只轮询有界分页。 */
    #[Route('/customer/tenants/{tenant}/exports', name: 'customer.exports', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}'])]
    public function exports(ServerRequestInterface $request): ResponseInterface
    {
        $raw = (new Input([]))->withQuery($request->getUri()->getQuery())->source('query');
        if (array_diff(array_keys($raw), ['page', 'per_page']) !== []) {
            throw new HttpError(422, 'export_filter_invalid');
        }
        $query = $this->query($request, []);
        return $this->response(200, $this->exports->search($this->connection($request), $this->identity($request), $this->tenant($request), $query['page'], $query['per_page']));
    }

    /** 取消和恢复是显式写操作，不修改冻结筛选或文件有效期。 */
    #[Route('/customer/tenants/{tenant}/exports/{export}/{action}', methods: ['POST'], name: 'customer.export-action', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'export' => '[a-f0-9]{32}', 'action' => 'cancel|resume'])]
    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->payload($request, []);
        $parameters = $request->getAttribute('type.route.params', []);
        return $this->response(200, ['data' => $parameters['action'] === 'cancel'
            ? $this->exports->cancel($this->connection($request), $this->identity($request), $this->tenant($request), $parameters['export'])
            : $this->exports->resume($this->connection($request), $this->identity($request), $this->tenant($request), $parameters['export'])]);
    }

    /** 下载没有可改写筛选的查询参数；鉴权后由既有HTTP流发送文件并回收资源。 */
    #[Route('/customer/tenants/{tenant}/exports/{export}/download', name: 'customer.export-download', middleware: ['customer.auth'], constraints: ['tenant' => '[a-f0-9]{32}', 'export' => '[a-f0-9]{32}'])]
    public function downloadExport(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getQuery() !== '') {
            throw new HttpError(422, 'export_filter_invalid');
        }
        $parameters = $request->getAttribute('type.route.params', []);
        $file = $this->exports->download($this->connection($request), $this->identity($request), $this->tenant($request), $parameters['export']);
        return $this->messages->createResponse(200)->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $file['name'] . '"')->withHeader('Content-Length', (string) $file['bytes'])
            ->withHeader('Cache-Control', 'private, no-store')->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->messages->createStreamFromFile($file['path'], 'rb'));
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
        if ($request->getUri()->getQuery() !== '') {
            throw new HttpError(422, 'export_filter_invalid');
        }
        if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
            throw new HttpError(415, 'json_required');
        }
        $input = Input::json(RequestBody::read($request->getBody(), 4096), 4096, 4);
        if (array_diff(array_keys($input->source('body')), array_keys($fields)) !== []) {
            throw new HttpError(422, 'export_filter_invalid');
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
            throw new \RuntimeException('导出接口需要受管请求作用域');
        }
        return \Type\Orm\Db::connection('default', true);
    }

    private function response(int $status, array $data): ResponseInterface
    {
        return $this->messages->createResponse($status)->withHeader('Content-Type', 'application/json; charset=utf-8')->withHeader('Cache-Control', 'no-store')
            ->withBody($this->messages->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}
