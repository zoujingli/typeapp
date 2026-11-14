<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存精确连接管理操作及其完成结果
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存精确连接管理操作及其完成结果';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_connection_operation')) {
            $schema->create('platform_mqtt_connection_operation', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('针对一条精确连接的管理操作及完成结果');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('kind', 32)->comment('连接操作种类');
                $table->string('owner_id', 32)->comment('目标连接资源所有者');
                $table->string('session_id', 32)->comment('目标会话标识');
                $table->bigInteger('session_generation')->comment('目标会话代次，避免作用到新连接');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->string('node_run_id', 32)->comment('目标节点运行代次');
                $table->string('observation_run', 32)->comment('操作依据的观察运行代次');
                $table->string('actor_id', 32)->comment('发起本次变更的真实操作者标识');
                $table->string('actor_realm', 16)->comment('操作者身份域，platform 或 system');
                $table->string('tenant_id', 32)->nullable()->comment('操作所属 Tenant；平台操作可为空');
                $table->bigInteger('requested_at')->comment('发起时间，UTC Unix 秒');
                $table->bigInteger('completed_at')->nullable()->comment('完成时间，UTC Unix 秒；未完成为空');
                $table->string('outcome', 20)->comment('完成结果');
                $table->primary(['id']);
                $table->index(['node_id', 'completed_at', 'requested_at', 'id'], 'platform_mqtt_connection_operation_node');
                $table->index(['owner_id', 'session_generation', 'id'], 'platform_mqtt_connection_operation_owner');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_connection_operation');
    }
};
