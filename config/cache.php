<?php

declare(strict_types=1);

// 默认不开启通用缓存，不初始化 Redis；开启后使用应用名、环境与格式的隔离命名空间。
return [
    'enabled' => env('APP_CACHE_ENABLED', false),
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DATABASE', 0),
        'username' => env('REDIS_USERNAME', ''),
        'password' => env('REDIS_PASSWORD', ''),
        'tls' => env('REDIS_TLS', false),
        'tls_ca' => env('REDIS_TLS_CA', ''),
    ],
];
