<?php

declare(strict_types=1);

namespace TypeApp\ModelExample;

use RuntimeException;
use Type\Orm\Connection;
use Type\Orm\Model;
use Type\Orm\ModelException;

/** CLI 与 HTTP 共用的事务验收路径，结束时只移除本次创建的记录。 */
final class TransactionExercise
{
    public static function verify(Connection $connection): array
    {
        $before = User::query($connection)->count();
        $state = [];
        $connection->transaction(static function (Connection $transaction) use (&$state): void {
            self::check($transaction->transactionDepth() === 1, '外层事务深度错误');
            $state['kept'] = new User(['name' => '外层保留', 'age' => 20, 'active' => true, 'secret' => '内部']);
            $state['kept']->save($transaction);
            try {
                $transaction->transaction(static function (Connection $inner) use (&$state): void {
                    self::check($inner->transactionDepth() === 2, '内层没有使用同一连接的 savepoint');
                    $state['inner'] = new User(['name' => '内层回滚', 'age' => 21, 'active' => true, 'secret' => '内部']);
                    $state['inner']->save($inner);
                    throw new RuntimeException('回滚内层');
                });
            } catch (RuntimeException $error) {
                self::check($error->getMessage() === '回滚内层', '内层原始错误没有保留');
            }
            self::invalid($state['inner']);
            self::check($state['kept']->getName() === '外层保留', '内层回滚影响了未参与内层的对象');
        });
        self::check(User::query($connection)->count() === $before + 1, '内层回滚范围不正确');
        try {
            $connection->transaction(static function (Connection $transaction) use (&$state): void {
                $state['partial'] = User::query($transaction)->select(['name'])->find($state['kept']->getId());
                $state['partial']->setName('应恢复');
                $state['partial']->save($transaction);
                $transaction->transaction(static function (Connection $inner) use (&$state): void {
                    $state['merged'] = new User(['name' => '内层成功待回滚', 'age' => 22, 'active' => true, 'secret' => '内部']);
                    $state['merged']->save($inner);
                    $state['clone'] = clone $state['merged'];
                });
                throw new RuntimeException('回滚外层');
            });
        } catch (RuntimeException $error) {
            self::check($error->getMessage() === '回滚外层', '外层原始错误没有保留');
        }
        self::invalid($state['partial']);
        self::invalid($state['merged']);
        self::invalid($state['clone']);
        self::check(User::query($connection)->count() === $before + 1
            && User::query($connection)->find($state['kept']->getId())->getName() === '外层保留', '外层没有回滚内层成功或部分字段写入');
        $rejected = false;
        try {
            $state['merged']->save($connection);
        } catch (ModelException $error) {
            $rejected = $error->errorCode() === 'model_invalid';
        }
        self::check($rejected, '回滚后的自增模型可以再次保存');
        $state['kept']->delete($connection);
        self::check($connection->transactionDepth() === 0 && User::query($connection)->count() === $before, '事务结束后仍有登记或残留记录');
        return ['inner_rollback' => true, 'outer_rollback' => true, 'partial_fields' => true, 'models_invalidated' => true];
    }

    private static function invalid(Model $model): void
    {
        $checks = 0;
        try {
            $model->get('id');
        } catch (ModelException $error) {
            if ($error->errorCode() === 'model_invalid') {
                $checks++;
            }
        }
        try {
            $model->related('profile');
        } catch (ModelException $error) {
            if ($error->errorCode() === 'model_invalid') {
                $checks++;
            }
        }
        try {
            json_encode($model, JSON_THROW_ON_ERROR);
        } catch (ModelException $error) {
            if ($error->errorCode() === 'model_invalid') {
                $checks++;
            }
        }
        try {
            serialize($model);
        } catch (ModelException $error) {
            if ($error->errorCode() === 'model_invalid') {
                $checks++;
            }
        }
        self::check($checks === 4, '失效模型仍能访问字段、关系或序列化');
    }

    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
