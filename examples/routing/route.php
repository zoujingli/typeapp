<?php

declare(strict_types=1);

return [
    'class' => 'TypeApp\\Generated\\Routes',
    'routes' => [
        [
            'prefix' => '/api',
            'name-prefix' => 'api.',
            'middleware' => ['group'],
            'routes' => [
                [
                    'resource' => '/books',
                    'name' => 'books',
                    'controller' => 'TypeApp\\RoutingExample\\BooksController',
                    'constraints' => ['id' => '[0-9]+'],
                ],
                [
                    'path' => '/lookup/{term}',
                    'methods' => ['GET'],
                    'name' => 'lookup',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'lookup'],
                    'middleware' => ['route'],
                ],
                [
                    'path' => '/books/typed/{id}',
                    'methods' => ['GET'],
                    'name' => 'typed.show',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'typedShow'],
                    'constraints' => ['id' => '[0-9]+'],
                ],
                [
                    'path' => '/books/typed',
                    'methods' => ['POST'],
                    'name' => 'typed.store',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'typedStore'],
                    'status' => 201,
                ],
                [
                    'path' => '/books/search/{term}',
                    'methods' => ['GET'],
                    'name' => 'typed.search',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'typedSearch'],
                    'constraints' => ['term' => '[^/]+'],
                ],
                [
                    'path' => '/books/typed/{id}',
                    'methods' => ['DELETE'],
                    'name' => 'typed.destroy',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'typedDestroy'],
                    'constraints' => ['id' => '[0-9]+'],
                ],
                [
                    'path' => '/inputs', 'methods' => ['GET'], 'name' => 'inputs.search',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'searchInput'],
                    'input' => ['maxQueryBytes' => 128, 'maxQueryFields' => 3],
                ],
                [
                    'path' => '/inputs', 'methods' => ['POST'], 'name' => 'inputs.create', 'status' => 201,
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'createInput'],
                    'input' => ['maxBytes' => 128, 'maxDepth' => 4],
                ],
                [
                    'path' => '/inputs/{id}', 'methods' => ['PATCH'], 'name' => 'inputs.patch',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'patchInput'],
                    'input' => ['maxBytes' => 128, 'maxDepth' => 4],
                ],
                [
                    'path' => '/inputs/{id}/sources', 'methods' => ['GET'], 'name' => 'inputs.sources',
                    'handler' => ['TypeApp\\RoutingExample\\BooksController', 'sourceInput'],
                ],
            ],
        ],
    ],
];
