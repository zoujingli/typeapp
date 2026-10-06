<?php

declare(strict_types=1);

// 受限启动配置；秘密值不进入源码、构建声明或消息追踪。
return [
    'redis_host' => env('TYPE_REDIS_HOST', '127.0.0.1'),
    'redis_port' => env('TYPE_REDIS_PORT', 6379),
    'namespace' => env('CATALOG_NAMESPACE', 'catalog-development'),
    'cursor' => env('CATALOG_CURSOR', 'var/catalog-schedule.json'),
    'clock' => env('CATALOG_CLOCK', 0),
    'https_url' => env('CATALOG_HTTPS_URL', ''),
    'https_ca' => env('CATALOG_HTTPS_CA', ''),
];
