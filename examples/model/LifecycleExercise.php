<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use RuntimeException;
use Type\Orm\Connection;
use Type\Orm\ModelBehavior;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;

/** 组合字段访问器、查询范围、软删除与事件，验证生命周期的可观察结果。 */
final class LifecycleExercise
{
    /**
     * 在当前连接验证生命周期与事务回滚，不将观察器记录当作提交事实。
     *
     * @return array<string, mixed> 演练结果摘要。
     */
    public static function verify(Connection $connection): array
    {
        $observer = new DocumentObserver();
        $behavior = (new ModelBehavior())->observe($observer)
            ->setter('title', static fn (mixed $value): mixed => is_string($value) ? strtolower(trim($value)) : $value)
            ->getter('title', static fn (mixed $value): mixed => strtoupper($value))
            ->getter('id', static fn (mixed $value): mixed => $value + 1000);
        $document = new Document(['title' => '  Hello  ', 'status' => 'draft'], false, $behavior);
        self::check($document->save() === 'created', '创建事件没有贯通');
        $stored = Document::query()->where('title', '=', 'hello')->first();
        $id = $stored->getId();
        self::check($document->getTitle() === 'HELLO' && $stored->getTitle() === 'hello'
            && $document->getId() === $id + 1000, '获取器或修改器影响了存储规则');
        self::check($observer->events() === ['saving', 'creating', 'created', 'saved'], '模型事件顺序错误');
        $observer->clear();
        self::check($document->save() === 'unchanged' && $observer->events() === [], '无变更触发了写入事件');
        $cancelled = new Document(['title' => 'cancel', 'status' => 'draft'], false, $behavior);
        self::check($cancelled->save() === 'cancelled' && !$cancelled->isPersisted()
            && Document::query()->count() === 1, '取消创建仍然持久化');
        $observer->clear();
        self::check($document->delete() && Document::query()->find($id) === null, '软删除默认过滤失效或展示主键改变写入目标');
        self::check(Document::query()->withTrashed()->count() === 1 && Document::query()->onlyTrashed()->count() === 1, '包含或仅已删除行为错误');
        self::check($observer->events() === ['deleting', 'deleted'] && $document->getDeletedAt() !== null, '删除事件或模型标记错误');
        $version = $document->getVersion();
        self::check(!$document->delete() && $document->getVersion() === $version, '重复软删除推进了版本');
        $observer->clear();
        self::check($document->restore() && Document::query()->count() === 1
            && $observer->events() === ['restoring', 'restored'], '恢复行为或事件错误');
        $version = $document->getVersion();
        self::check(!$document->restore() && $document->getVersion() === $version, '重复恢复推进了版本');
        $other = new Document(['title' => 'other', 'status' => 'archived']);
        $other->save();
        $base = Document::query();
        $filtered = $base->scope(static fn (ModelQuery $query): ModelQuery => $query->where('status', '=', 'draft'))
            ->search(['keyword' => 'hel'], ['keyword' => static fn (ModelQuery $query, mixed $value): ModelQuery => $query->where('title', 'LIKE', '%' . $value . '%')]);
        self::check($filtered->count() === 1 && $base->count() === 2, '范围或搜索器修改了共享查询');
        $observer->clear();
        $connection->table('type_model_documents')->where('id', '=', $id)->update(['title' => 'bulk']);
        self::check($observer->events() === [], '批量查询冒充逐模型事件');
        $changed = Document::query()->withBehavior($behavior)->find($id);
        self::check($changed->getTitle() === 'BULK' && $observer->events() === ['retrieved'], '读取事件或获取器失效');
        $changed->setTitle(' fail ');
        try {
            $changed->save();
        } catch (RuntimeException $error) {
            self::check($error->getMessage() === '后置事件失败', '后置事件错误丢失');
        }
        self::check(Document::query()->find($id)->getTitle() === 'bulk', '后置事件失败后没有回滚写入');
        $invalid = false;
        try {
            $changed->getTitle();
        } catch (ModelException $error) {
            $invalid = $error->errorCode() === 'model_invalid';
        }
        self::check($invalid, '事件失败后的模型没有失效');
        $observer->clear();
        $fresh = Document::query()->withBehavior($behavior)->find($id);
        $observer->clear();
        self::check($fresh->forceDelete() && Document::query()->withTrashed()->find($id) === null
            && $observer->events() === ['forceDeleting', 'forceDeleted'], '强制删除或事件不正确');
        $other->forceDelete();
        return ['soft_delete' => true, 'restore' => true, 'scopes' => true, 'events' => true, 'cancelled' => true];
    }

    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
