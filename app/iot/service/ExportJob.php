<?php

declare(strict_types=1);

namespace app\iot\service;

use Type\Orm\Database;
use Type\Queue\Job;
use Type\Queue\JobContext;

/** 把队列租约及任务作用域绑定到一次导出分块；连接在成功和异常路径都归还。 */
final class ExportJob implements Job
{
    /** 复用后台角色拥有的池；Job不创建第二套连接配置。 */
    public function __construct(private Database $database, private ExportService $exports)
    {
    }

    /** @param array{id: string, step: int} $payload 服务端Outbox消息，不接受用户SQL或文件路径。 */
    public function handle(JobContext $context, array $payload): void
    {
        $context->assertActive();
        $connection = $this->database->connect($context->scope());
        try {
            $this->exports->advance($connection, $context, $payload);
        } catch (\RuntimeException $failure) {
            if ($failure->getMessage() !== 'export_storage_unavailable') {
                throw $failure;
            }
            $this->exports->fail($connection, $context, $payload, 'export_storage_unavailable');
        } finally {
            $connection->close();
        }
    }
}
