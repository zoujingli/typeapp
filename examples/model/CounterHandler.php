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
use Type\Orm\TransactionException;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

/** 通过 HTTP 演示带版本字段的计数器更新，拒绝旧客户端覆盖。 */
final class CounterHandler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /** 注入 PSR 工厂，模型连接由当前请求作用域提供。 */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** 校验显式版本并保存计数器，区分字段错误、404、409 与事务结果未知。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('版本请求缺少作用域');
        }
        try {
            $method = $request->getMethod();
            $counter = null;
            if ($method !== 'POST') {
                $query = (new Schema(['id' => Field::integer()->from('query')->cast()->required()->range(1, PHP_INT_MAX)]))
                    ->validate((new Input([]))->withQuery($request->getUri()->getQuery()));
                $counter = Counter::query()->find($query->get('id'));
                if ($counter === null) {
                    throw new ModelException('not_found', '记录不存在');
                }
            }
            $status = 200;
            if ($method !== 'GET') {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    throw new ValidationException(['body' => ['json_required']], 415, 'unsupported_media_type');
                }
                $fields = ['value' => Field::integer()->required()];
                if ($method === 'PATCH') {
                    $fields['version'] = Field::integer()->required()->range(1, PHP_INT_MAX);
                }
                $data = (new Schema($fields))->validate(Input::json($request->getBody()->getContents(), 4096, 8));
                if ($counter === null) {
                    $counter = new Counter(['value' => $data->get('value')]);
                    $status = 201;
                } else {
                    if ($counter->getVersion() !== $data->get('version')) {
                        throw new ModelException('optimistic_conflict', '版本已过期');
                    }
                    $counter->setValue($data->get('value'));
                }
                $counter->save();
            }
            $result = ['data' => $counter->project(['id', 'value', 'version'])];
        } catch (ValidationException $error) {
            $status = $error->status();
            $result = ['error' => $error->errorCode(), 'fields' => $error->errors()];
        } catch (ModelException $error) {
            if ($error->errorCode() === 'not_found') {
                $status = 404;
            } elseif ($error->errorCode() === 'optimistic_conflict') {
                $status = 409;
            } else {
                throw $error;
            }
            $result = ['error' => $error->errorCode()];
        } catch (TransactionException $error) {
            $status = 503;
            $result = ['error' => 'transaction_failed', 'outcome' => $error->outcome()];
        }
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_THROW_ON_ERROR)));
    }
}
