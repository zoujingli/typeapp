<?php

declare(strict_types=1);

// 端口为 0 时由选定驱动取默认值；相对 SQLite 路径基于 APP_BASE_PATH。
return [
    'driver' => env('DB_DRIVER', 'sqlite'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', 0),
    'database' => env('DB_DATABASE', 'typeapp'),
    'username' => env('DB_USERNAME', 'typeapp'),
    'password' => env('DB_PASSWORD', ''),
    'sqlite_file' => env('DB_SQLITE_FILE', 'build/app/runtime/typeapp.sqlite'),
    'tls_ca' => env('DB_TLS_CA', ''),
    'budget' => [
        'server' => env('DB_SERVER_BUDGET', 100),
        'replicas' => env('APP_MAX_REPLICAS', 1),
        'surge' => env('APP_ROLLING_SURGE', 1),
        'processes' => env('APP_DATABASE_PROCESSES', 1),
        'threads' => env('APP_DATABASE_THREADS', 2),
        'reserve' => env('DB_ADMIN_RESERVE', 10),
    ],
    'pool' => [
        'waiters' => env('DB_POOL_WAITERS', 64),
        'wait_ms' => env('DB_POOL_WAIT_MS', 1000),
    ],
];
