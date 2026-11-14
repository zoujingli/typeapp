<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存有界 Broker 运行、连接和真实订阅观察
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存有界 Broker 运行、连接和真实订阅观察';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_node')) {
            $schema->create('platform_mqtt_node', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('有界 Broker 节点的最近一次运行观察');
                $table->integer('slot')->check('slot >= 0 AND slot < 32')->comment('节点槽位，取值 0 到 31');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->string('run_id', 32)->unique()->comment('节点本次运行代次');
                $table->bigInteger('observed_at')->comment('最近一次观察时间，UTC Unix 秒');
                $table->integer('stopped')->comment('节点是否已停止上报');
                $table->text('listener_json')->comment('当前监听地址与端口的观察快照');
                $table->text('metrics_json')->comment('连接、提交与拒绝等运行计数快照');
                $table->primary(['slot']);
            });
        }
        if (!$schema->hasTable('platform_mqtt_resource_run')) {
            $schema->create('platform_mqtt_resource_run', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('节点一次运行的连接容量与过期边界');
                $table->integer('slot')->check('slot >= 0 AND slot < 32')->comment('运行占用的节点槽位，取值 0 到 31');
                $table->string('node_id', 64)->unique()->comment('MQTT 节点标识');
                $table->string('run_id', 32)->unique()->comment('节点本次运行代次');
                $table->bigInteger('observed_at')->comment('最近一次观察时间，UTC Unix 秒');
                $table->bigInteger('expires_at')->comment('本次运行观察的失效时间，UTC Unix 秒');
                $table->integer('connections')->default(0)->check('connections >= 0 AND connections <= 10100')->comment('本次运行已观察的连接数量');
                $table->primary(['slot']);
            });
        }
        if (!$schema->hasTable('platform_mqtt_resource_connection')) {
            $schema->create('platform_mqtt_resource_connection', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('Broker 上一条真实连接的资源观察');
                $table->string('owner_id', 32)->comment('连接资源所有者标识');
                $table->string('observation_run', 32)->comment('产生本观察的节点运行代次');
                $table->text('client_id')->comment('MQTT Client ID');
                $table->string('client_hash', 64)->comment('Client ID 摘要，用于索引');
                $table->string('session_id', 32)->comment('会话标识');
                $table->bigInteger('session_generation')->comment('会话代次，用于拒绝过期连接');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->string('node_run_id', 32)->comment('承载该连接的节点运行代次');
                $table->bigInteger('node_generation')->comment('节点代次');
                $table->string('resource_scope', 128)->nullable()->comment('连接所属资源范围；未绑定业务范围时为空');
                $table->string('scope_hash', 64)->nullable()->comment('资源范围摘要；未绑定业务范围时为空');
                $table->text('access_identity')->nullable()->comment('认证时确认的接入身份；未认证时为空');
                $table->integer('protocol')->comment('MQTT 协议级别');
                $table->string('transport', 10)->comment('传输方式');
                $table->integer('durable')->comment('是否为持久会话');
                $table->bigInteger('observed_at')->comment('最近一次观察时间，UTC Unix 秒');
                $table->primary(['owner_id']);
                $table->index(['scope_hash', 'owner_id'], 'platform_mqtt_resource_connection_scope');
                $table->index(['observation_run', 'owner_id'], 'platform_mqtt_resource_connection_run');
                $table->index(['client_hash', 'owner_id'], 'platform_mqtt_resource_connection_client');
                $table->foreign('observation_run', 'platform_mqtt_resource_run', 'run_id', 'CASCADE');
            });
        }
        if (!$schema->hasTable('platform_mqtt_resource_subscription')) {
            $schema->create('platform_mqtt_resource_subscription', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('真实连接上的订阅观察');
                $table->string('id', 64)->comment('连接与订阅过滤器共同决定的稳定标识');
                $table->string('owner_id', 32)->comment('所属连接资源所有者');
                $table->text('topic')->comment('客户端声明的订阅过滤器');
                $table->string('topic_hash', 64)->comment('订阅过滤器摘要，用于索引');
                $table->text('actual_filter')->comment('去掉共享订阅组后的实际过滤器');
                $table->integer('options')->comment('订阅选项位');
                $table->bigInteger('identifier')->comment('订阅标识符');
                $table->bigInteger('observed_at')->comment('最近一次观察时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['owner_id', 'id'], 'platform_mqtt_resource_subscription_owner');
                $table->index(['topic_hash', 'id'], 'platform_mqtt_resource_subscription_topic');
                $table->foreign('owner_id', 'platform_mqtt_resource_connection', 'owner_id', 'CASCADE');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_resource_subscription');
        $schema->dropIfExists('platform_mqtt_resource_connection');
        $schema->dropIfExists('platform_mqtt_resource_run');
        $schema->dropIfExists('platform_mqtt_node');
    }
};
