<?php

declare(strict_types=1);

// 仅识别旧部署的开关并明确拒绝 true；物联中心没有通用业务缓存消费者。
// 通知、导出与调度的 Redis 配置分别位于 app 对应用途，不受此键控制。
return [
    'enabled' => env('APP_CACHE_ENABLED', false),
];
