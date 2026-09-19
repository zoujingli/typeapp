<?php

declare(strict_types=1);

namespace TypeApp\Integration;

use Type\Orm\Connection;
use Type\Orm\Database;
use Type\Orm\Outbox\Store;
use Type\Queue\Job;
use Type\Queue\JobContext;
use Type\Redis\RedisManager;
use TypeApp\OrmSuite\Article;

final class ArticleJob implements Job
{
    private Database $database;
    private Store $outbox;
    private RedisManager $redis;
    private string $application;
    private bool $audit;
    public function __construct(Database $database, Store $outbox, RedisManager $redis, string $application, bool $audit = false)
    {
        $this->database = $database;
        $this->outbox = $outbox;
        $this->redis = $redis;
        $this->application = $application;
        $this->audit = $audit;
    }
    public function handle(JobContext $context, array $payload): void
    {
        if (!is_int($payload['article_id'] ?? null) || $payload['article_id'] < 1) {
            throw new \RuntimeException('文章任务载荷无效');
        }
        $context->assertActive();
        $id = $payload['article_id'];
        $connection = $this->database->connect($context->scope());
        $connection->transaction(function (Connection $transaction) use ($context, $id): void {
            if ($this->audit) {
                if ($transaction->table('integration_audits')->where('id', '=', $context->message()->id())->first() !== null) {
                    return;
                }
                $article = Article::query($transaction)->find($id);
                $transaction->table('integration_audits')->insert(['id' => $context->message()->id(), 'article_id' => $id, 'observed_views' => $article->getViews()]);
                $article->setStatus('audited');
                $article->save($transaction);
            } elseif ($this->outbox->consumed($transaction, $context->message()->id(), 'article:' . $id)) {
                $article = Article::query($transaction)->find($id);
                $article->setViews($article->getViews() + 1);
                $article->save($transaction);
            }
        });
        // 重投时同样再尝试失效；已提交的模型效果由消费凭据保持幂等。
        Scenario::cache($this->redis, $context->scope(), $this->application)->delete('article:' . $id);
    }
}
