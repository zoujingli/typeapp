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
            ],
        ],
    ],
];
