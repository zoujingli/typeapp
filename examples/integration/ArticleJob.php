<?php

declare(strict_types=1);

namespace TypeApp\Integration;

use Type\Orm\Connection;
use Type\Orm\Db;
use Type\Orm\Outbox\Store;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Redis\RedisManager;
use TypeApp\OrmSuite\Article;

/** 消费文章消息，将 Outbox 凭据、模型变更与缓存失效组合成业务闭环。 */
final class ArticleJob implements Job
{
    private Store $outbox;
    private RedisManager $redis;
    private string $application;
    private bool $audit;
    /** 注入 Outbox 与 Redis 管理器，审查模式由显式参数选择。 */
    public function __construct(Store $outbox, RedisManager $redis, string $application, bool $audit = false)
    {
        $this->outbox = $outbox;
        $this->redis = $redis;
        $this->application = $application;
        $this->audit = $audit;
    }
    /**
     * 校验文章 ID 并在任务作用域内执行幂等业务，相关缓存按提交结果处理。
     *
     * @param array{article_id: int} $payload 业务文章身份。
     */
    public function handle(JobContext $context, array $payload): void
    {
        if (!is_int($payload['article_id'] ?? null) || $payload['article_id'] < 1) {
            throw new \RuntimeException('文章任务载荷无效');
        }
        $context->assertActive();
        $id = $payload['article_id'];
        $connection = Db::connection('default', true);
        $connection->transaction(function (Connection $transaction) use ($context, $id): void {
            if ($this->audit) {
                if ($transaction->table('integration_audits')->where('id', '=', $context->message()->id())->first() !== null) {
                    return;
                }
                $article = Article::query()->find($id);
                $transaction->table('integration_audits')->insert(['id' => $context->message()->id(), 'article_id' => $id, 'observed_views' => $article->getViews()]);
                $article->setStatus('audited');
                $article->save();
            } elseif ($this->outbox->consumed($transaction, $context->message()->id(), 'article:' . $id)) {
                $article = Article::query()->find($id);
                $article->setViews($article->getViews() + 1);
                $article->save();
            }
        });
        // 重投时同样再尝试失效；已提交的模型效果由消费凭据保持幂等。
        Scenario::cache($this->redis, $context->scope(), $this->application)->delete('article:' . $id);
    }
}
