<?php

declare(strict_types=1);

namespace TypeApp\RoutingExample;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Type\Core\Http\Attribute\Group;
use Type\Core\Http\Attribute\Resource;
use Type\Core\Http\Attribute\Route;
use Type\Core\Http\Message\Factory;

#[Group(prefix: '/api', namePrefix: 'api.', middleware: ['group'])]
#[Resource(path: '/books', name: 'books', constraints: ['id' => '[0-9]+'])]
final class BooksController
{
    private string $greeting;

    public function __construct(string $greeting)
    {
        $this->greeting = $greeting;
    }

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'index');
    }
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'create');
    }
    public function store(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'store');
    }
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'show');
    }
    public function edit(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'edit');
    }
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'update');
    }
    public function destroy(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'destroy');
    }

    #[Route(path: '/lookup/{term}', methods: ['GET'], name: 'lookup', middleware: ['route'])]
    public function lookup(ServerRequestInterface $request): ResponseInterface
    {
        return $this->reply($request, 'lookup');
    }

    private function reply(ServerRequestInterface $request, string $action): ResponseInterface
    {
        $messages = new Factory();
        return $messages->createResponse()->withHeader('Content-Type', 'application/json')
            ->withBody($messages->createStream((string) json_encode([
                'greeting' => $this->greeting, 'action' => $action, 'route' => $request->getAttribute('type.route'),
                'parameters' => $request->getAttribute('type.route.params'), 'trace' => $request->getAttribute('trace', ''),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
    }
}
