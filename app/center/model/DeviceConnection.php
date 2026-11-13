<?php

declare(strict_types=1);

namespace app\center\model;

use Type\Orm\Model;

/**
 * 设备当前连接观察投影；网络会话由 MQTT 组件拥有，本模型只保存最后可信观察事实。
 *
 * @property string $device_id 设备的稳定标识，也是本投影主键。
 * @property string $ownership_id 观察对应的设备使用关系。
 * @property null|string $owner_id 当前连接资源所有者标识。
 * @property null|string $node_id 产生观察的 MQTT 节点标识。
 * @property null|string $run_id 节点运行代次。
 * @property string $status 当前连接观察状态。
 * @property null|int $observed_at 最近一次观察时间，UTC Unix 秒。
 * @method static \Type\Orm\ModelQuery<static> query(string $alias = '')
 * @method static static|null find(int|string $id)
 * @method static static create(array $values)
 */
final class DeviceConnection extends Model
{
    protected string $table = 'center_device_connection';

    protected bool $autoIncrement = false;

    protected string $pk = 'device_id';

    protected array $schema = [
        'device_id' => ['type' => 'string', 'comment' => '设备的稳定标识，也是本投影主键。'],
        'ownership_id' => ['type' => 'string', 'comment' => '观察对应的设备使用关系。'],
        'owner_id' => ['type' => 'string', 'nullable' => true, 'comment' => '当前连接资源所有者标识。'],
        'node_id' => ['type' => 'string', 'nullable' => true, 'comment' => '产生观察的 MQTT 节点标识。'],
        'run_id' => ['type' => 'string', 'nullable' => true, 'comment' => '节点运行代次。'],
        'status' => ['type' => 'string', 'comment' => '当前连接观察状态。'],
        'observed_at' => ['type' => 'integer', 'nullable' => true, 'comment' => '最近一次观察时间，UTC Unix 秒。'],
    ];
}
