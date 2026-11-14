<?php

declare(strict_types=1);

namespace app\system\model;

use Type\Orm\Model;

/**
 * 客户系统身份与业务操作的审计事实
 * @property string $id 审计事件的稳定标识
 * @property string $tenant_id 所属 Tenant 标识，由可信执行上下文约束
 * @property string $actor_id 真实操作者标识
 * @property string $action 已登记的审计动作
 * @property string $subject_id 被操作对象的稳定标识
 * @property string $result 结果：success、denied、failed 或 unknown
 * @property string $details 不含秘密的有界附加事实
 * @property int $created_at 创建时间，UTC Unix 秒
 * @property string $category 审计类别，默认 identity
 * @property string|null $operation_id 同一业务操作的关联标识
 * @property string|null $event_key 同一操作内的事件键，用于拒绝重复事实
 * @property string|null $request_id 来源请求标识
 * @property string|null $stage 操作阶段
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static \Type\Orm\ModelQuery<static> where(string|array $field, mixed $operator = null, mixed $value = null, string $boolean = 'AND')
 * @method static \Type\Orm\ModelQuery<static> order(string $field, string $direction = 'ASC')
 * @method static \Type\Orm\ModelQuery<static> limit(int $count, int $offset = 0)
 * @method static \Type\Orm\ModelQuery<static> with(string $name, ?\Closure $relation = null)
 * @method static static|null find(int|string $id)
 * @method static bool destroy(int|string $id)
 * @method static static create(array $values)
 * @method static static firstOrCreate(array $identity, array $values = [])
 * @method static \Type\Orm\Helper\QueryHelper search(array $input = [], string $alias = '')
 */
final class Audit extends Model
{
    protected string $table = 'system_audit';
    protected string $pk = 'id';

    protected bool $autoIncrement = false;

    protected ?string $tenantKey = 'tenant_id';

    protected array $schema = [
        'id' => ['type' => 'string', 'comment' => '审计事件的稳定标识'],
        'tenant_id' => ['type' => 'string', 'comment' => '所属 Tenant 标识，由可信执行上下文约束'],
        'actor_id' => ['type' => 'string', 'comment' => '真实操作者标识'],
        'action' => ['type' => 'string', 'comment' => '已登记的审计动作'],
        'subject_id' => ['type' => 'string', 'comment' => '被操作对象的稳定标识'],
        'result' => ['type' => 'string', 'comment' => '结果：success、denied、failed 或 unknown'],
        'details' => ['type' => 'string', 'comment' => '不含秘密的有界附加事实'],
        'created_at' => ['type' => 'integer', 'comment' => '创建时间，UTC Unix 秒'],
        'category' => ['type' => 'string', 'required' => false, 'comment' => '审计类别，默认 identity'],
        'operation_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '同一业务操作的关联标识'],
        'event_key' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '同一操作内的事件键，用于拒绝重复事实'],
        'request_id' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '来源请求标识'],
        'stage' => ['type' => 'string', 'nullable' => true, 'required' => false, 'comment' => '操作阶段'],
    ];
}
