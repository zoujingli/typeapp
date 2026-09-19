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

final class Endpoint implements RequestHandlerInterface
{
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

final class Lifecycle implements ManagedResource
{
    private ExecutionScope $scope;
    private string $marker;
    public function __construct(ExecutionScope $scope, string $marker)
    {
        $this->scope = $scope;
        $this->marker = $marker;
    }
    public function start(): void
    {
    }
    public function stop(): void
    {
        file_put_contents((string) getenv('TYPE_UPLOAD_TRACE'), 'scope:' . $this->marker . ':' . $this->scope->state() . "\n", FILE_APPEND | LOCK_EX);
    }
}
