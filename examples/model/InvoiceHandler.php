<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Type\Orm\ModelException;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

/** 通过 HTTP 验证发票的精确数值与时间字段，不以浮点数替代金额文本。 */
final class InvoiceHandler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /** 注入消息工厂；运行时模型查询使用请求作用域连接。 */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** 校验发票输入并保存或读取模型，输出安全字段及明确错误状态。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('金额请求缺少作用域');
        }
        try {
            $allowed = ['id', 'external_id', 'amount', 'happened_at', 'note'];
            $queryFields = ['id' => Field::integer()->from('query')->cast()->range(1, PHP_INT_MAX),
                'fields' => Field::listOf(Field::text()->oneOf($allowed))->from('query')->length(1, 5)];
            if ($request->getMethod() !== 'POST') {
                $queryFields['id'] = $queryFields['id']->required();
            }
            $parameters = (new Schema($queryFields))->validate((new Input([]))->withQuery($request->getUri()->getQuery()));
            $fields = $parameters->has('fields') ? $parameters->get('fields') : $allowed;
            $model = null;
            if ($request->getMethod() !== 'POST') {
                $model = Invoice::query()->select($fields)->find($parameters->get('id'));
                if ($model === null) {
                    throw new ModelException('not_found', '账单不存在');
                }
            }
            $status = 200;
            if ($request->getMethod() !== 'GET') {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    throw new ValidationException(['body' => ['json_required']], 415, 'unsupported_media_type');
                }
                $data = (new Schema(['external_id' => Field::text()->required(), 'amount' => Field::text()->required(),
                    'happened_at' => Field::text()->required(), 'note' => Field::text()->nullable()->length(0, 255)]))
                    ->validate(Input::json($request->getBody()->getContents(), 4096, 8), 'default', $request->getMethod() === 'PATCH');
                if ($model === null) {
                    $model = new Invoice($data->toArray());
                    $status = 201;
                } else {
                    $model->fill($data->toArray());
                }
                $model->save();
                $model = Invoice::query()->find($model->getId());
            }
            $result = ['data' => $model->project($fields)];
        } catch (ValidationException $error) {
            $status = $error->status();
            $result = ['error' => $error->errorCode(), 'fields' => $error->errors()];
        } catch (ModelException $error) {
            if ($error->errorCode() === 'not_found') {
                $status = 404;
            } elseif (in_array($error->errorCode(), ['invalid_field_type', 'scale_exceeded', 'precision_exceeded', 'invalid_datetime'], true)) {
                $status = 422;
            } else {
                throw $error;
            }
            $result = ['error' => $error->errorCode()];
        }
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
