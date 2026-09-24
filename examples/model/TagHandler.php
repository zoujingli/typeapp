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

/** 以 HTTP 验证文章标签关系的挂载、解除与同步。 */
final class TagHandler implements RequestHandlerInterface
{
    private ResponseFactoryInterface $responses;
    private StreamFactoryInterface $streams;

    /** 注入消息工厂，不在处理器构造时借用数据库连接。 */
    public function __construct(ResponseFactoryInterface $responses, StreamFactoryInterface $streams)
    {
        $this->responses = $responses;
        $this->streams = $streams;
    }

    /** 从当前作用域执行关系操作，先校验输入再输出显式业务结果。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $request->getAttribute('type.scope');
        if (!$scope instanceof ExecutionScope) {
            throw new RuntimeException('标签请求缺少作用域');
        }
        try {
            $query = (new Schema(['id' => Field::integer()->from('query')->cast()->required()->range(1, PHP_INT_MAX)]))
                ->validate((new Input([]))->withQuery($request->getUri()->getQuery()));
            $article = Article::query()->find($query->get('id'));
            if ($article === null) {
                throw new ModelException('not_found', '文章不存在');
            }
            $tags = ArticleTags::relation();
            if ($request->getMethod() !== 'GET') {
                if (strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0])) !== 'application/json') {
                    throw new ValidationException(['body' => ['json_required']], 415, 'unsupported_media_type');
                }
                $input = Input::json($request->getBody()->getContents(), 65536, 8);
                $position = Field::integer()->range(0, 100000);
                if ($request->getMethod() === 'PATCH') {
                    $schema = new Schema(['items' => Field::listOf(Field::object(new Schema([
                        'id' => Field::integer()->required()->range(1, PHP_INT_MAX),
                        'pivot' => Field::object(new Schema(['position' => $position])),
                    ])))->required()->length(0, 500)]);
                    $data = $schema->validate($input);
                    $tags->sync($article, $data->get('items'));
                } else {
                    $data = (new Schema(['tag_id' => Field::integer()->required()->range(1, PHP_INT_MAX), 'position' => $position]))->validate($input);
                    if ($request->getMethod() === 'DELETE') {
                        $tags->detach($article, $data->get('tag_id'));
                    } else {
                        $tags->attach($article, $data->get('tag_id'), $data->has('position') ? ['position' => $data->get('position')] : []);
                    }
                }
            }
            $article = Article::query()->with('tags', $tags)->find($query->get('id'));
            $result = ['data' => []];
            foreach ($article->related('tags') as $tag) {
                $result['data'][] = $tag->project(['id', 'label']) + ['pivot' => $tag->pivot()];
            }
            $status = 200;
        } catch (ValidationException $error) {
            $status = $error->status();
            $result = ['error' => $error->errorCode(), 'fields' => $error->errors()];
        } catch (TransactionException $error) {
            $status = $error->outcome() === 'COMMITTED' ? 500 : 503;
            $result = ['error' => 'transaction_failed', 'outcome' => $error->outcome()];
        } catch (ModelException $error) {
            if (in_array($error->errorCode(), ['not_found', 'related_not_found'], true)) {
                $status = 404;
            } elseif (in_array($error->errorCode(), ['invalid_pivot_input', 'duplicate_pivot_input', 'invalid_pivot_field'], true)) {
                $status = 422;
            } elseif ($error->errorCode() === 'relation_parent_changed') {
                $status = 409;
            } else {
                throw $error;
            }
            $result = ['error' => $error->errorCode()];
        }
        return $this->responses->createResponse($status)->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
