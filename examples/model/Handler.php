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
use Type\Orm\Connection;
use Type\Orm\ModelQuery;
use Type\Orm\Relation;
use Type\Runtime\ExecutionScope;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

/** 通过真实 HTTP 演示用户模型 CRUD 与校验错误映射。 */
final class Handler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /** 注入 PSR 响应和流工厂，数据库由当前作用域解析。 */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** 在请求 Scope 中处理用户读取与写入，将字段错误和不存在映射为明确响应。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('用户请求缺少执行作用域');
        }
        try {
            $method = $request->getMethod();
            $input = (new Input([]))->withQuery($request->getUri()->getQuery());
            $queryFields = ['id' => Field::integer()->from('query')->cast()->range(1, PHP_INT_MAX),
                'fields' => Field::listOf(Field::text()->oneOf(['id', 'name', 'age', 'active', 'note']))->from('query')->length(1, 5),
                'with' => Field::listOf(Field::text()->oneOf(['articles', 'profile']))->from('query')->length(1, 2)];
            if (in_array($method, ['PATCH', 'DELETE'], true)) {
                $queryFields['id'] = $queryFields['id']->required();
            }
            $parameters = (new Schema($queryFields))->validate($input);
            if ($method !== 'GET' && $parameters->has('with')) {
                throw new ValidationException(['with' => ['get_only']]);
            }
            $fields = $parameters->has('fields') ? $parameters->get('fields') : ['id', 'name', 'age', 'active', 'note'];
            $query = User::query()->select($fields);
            $relationFields = [];
            foreach ($parameters->has('with') ? $parameters->get('with') : [] as $name) {
                if ($name === 'articles') {
                    $query = $query->with('articles', Relation::hasMany(static fn (Connection $connection): ModelQuery => Article::query()->onConnection($connection), 'user_id'));
                    $relationFields['articles'] = ['id', 'title'];
                } else {
                    $query = $query->with('profile', Relation::hasOne(static fn (Connection $connection): ModelQuery => Profile::query()->onConnection($connection), 'user_id'));
                    $relationFields['profile'] = ['bio'];
                }
            }
            $status = 200;
            if ($method === 'GET') {
                if ($parameters->has('id')) {
                    $user = $query->find($parameters->get('id'));
                    if ($user === null) {
                        throw new ModelException('not_found', '用户不存在');
                    }
                    $result = ['data' => $user->project($fields, $relationFields)];
                } else {
                    $result = ['data' => []];
                    foreach ($query->orderBy('id')->limit(100)->get() as $user) {
                        $result['data'][] = $user->project($fields, $relationFields);
                    }
                }
            } elseif ($method === 'DELETE') {
                $user = $query->find($parameters->get('id'));
                if ($user === null) {
                    throw new ModelException('not_found', '用户不存在');
                }
                $result = ['deleted' => $user->delete()];
            } else {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    throw new ValidationException(['body' => ['json_required']], 415, 'unsupported_media_type');
                }
                $data = (new Schema([
                    'name' => Field::text()->required()->trim()->length(2, 100),
                    'age' => Field::integer()->required()->range(0, 150),
                    'active' => Field::boolean()->required(),
                    'secret' => Field::text()->required()->length(1, 255),
                    'note' => Field::text()->nullable()->length(0, 255),
                ]))->validate(Input::json($request->getBody()->getContents(), 4096, 8), 'default', $method === 'PATCH');
                if ($method === 'POST') {
                    $user = new User($data->toArray());
                    $status = 201;
                } else {
                    $user = $query->find($parameters->get('id'));
                    if ($user === null) {
                        throw new ModelException('not_found', '用户不存在');
                    }
                    $user->fill($data->toArray());
                }
                $user->save();
                $user = User::query()->find($user->getId());
                $result = ['data' => $user->project($fields)];
            }
        } catch (ValidationException $error) {
            $status = $error->status();
            $result = ['error' => $error->errorCode(), 'fields' => $error->errors()];
        } catch (ModelException $error) {
            if ($error->errorCode() !== 'not_found') {
                throw $error;
            }
            $status = 404;
            $result = ['error' => $error->errorCode()];
        }
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
