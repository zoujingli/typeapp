<?php

declare(strict_types=1);

namespace TypeApp\FileExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Type\Core\Http\Message\Factory;
use Type\Core\Http\UploadStorage;
use Type\Runtime\ExecutionScope;
use Type\Runtime\ManagedResource;

/** 提供上传、临时文件和分块响应演练，所有文件仅在专属测试目录内处理。 */
final class Endpoint implements RequestHandlerInterface
{
    /** 按路径选择上传或下载场景，资源登记到当前 HTTP Scope 后再返回响应。 */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $factory = new Factory();
        $scope = $request->getAttribute('type.scope');
        $directory = (string) getenv('TYPE_UPLOAD_DIRECTORY');
        $storage = new UploadStorage($directory, 196608, 4);
        $mode = $request->getUri()->getPath();
        $marker = $request->getHeaderLine('X-Test-Marker');
        $scope->open(new Lifecycle($scope, $marker));
        if (in_array($mode, ['/download', '/fail-stream', '/fail-first', '/timeout-stream', '/disconnect'], true)) {
            return $factory->createResponse()->withHeader('Content-Type', 'application/octet-stream')
                ->withBody(new DownloadStream($factory->createStreamFromFile((string) getenv('TYPE_DOWNLOAD_FILE')), $scope, $marker, $mode));
        }
        if ($mode === '/stats') {
            $data = $storage->statistics();
        } elseif ($mode === '/form') {
            $data = ['fields' => $request->getParsedBody()];
        } elseif ($mode === '/file') {
            return $factory->createResponse()->withBody($storage->open((string) ($request->getQueryParams()['key'] ?? '')));
        } else {
            $files = $request->getUploadedFiles();
            $keys = [];
            $sources = $files === [] ? [$request->getBody()] : array_map(static fn (UploadedFileInterface $file): StreamInterface => $file->getStream(), $files['files'] ?? array_values($files));
            foreach ($sources as $source) {
                $pending = $storage->receive($source, $scope, 131072);
                if ($mode === '/save') {
                    $keys[] = $pending->save();
                }
            }
            if ($mode === '/fail-upload') {
                throw new \RuntimeException('上传后业务异常');
            }
            $data = ['keys' => $keys, 'fields' => $request->getParsedBody()];
        }
        return $factory->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
    }
}

/** 记录请求作用域清理时刻，与响应流关闭迹线一起核对顺序。 */
final class Lifecycle implements ManagedResource
{
    private ExecutionScope $scope;
    private string $marker;
    /** 绑定本次 Scope 与迹线标记，不在构造时写文件。 */
    public function __construct(ExecutionScope $scope, string $marker)
    {
        $this->scope = $scope;
        $this->marker = $marker;
    }
    /** 不分配额外资源，观察重点在请求退出的 stop。 */
    public function start(): void
    {
    }
    /** 记录作用域状态，供外部测试检查流关闭与 Scope 退出的先后。 */
    public function stop(): void
    {
        file_put_contents((string) getenv('TYPE_UPLOAD_TRACE'), 'scope:' . $this->marker . ':' . $this->scope->state() . "\n", FILE_APPEND | LOCK_EX);
    }
}
