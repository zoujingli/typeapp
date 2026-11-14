<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存可在线调整的连接、会话与消息额度版本及节点生效
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存可在线调整的连接、会话与消息额度版本及节点生效';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_quota_revision')) {
            $schema->create('platform_mqtt_quota_revision', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('可在线调整的连接、会话与消息额度版本');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->bigInteger('version')->unique()->comment('发布代次，单调递增');
                $table->string('actor_id', 32)->comment('发起本次变更的真实操作者标识');
                $table->string('actor_realm', 16)->comment('操作者身份域，platform 或 system');
                $table->integer('lowering')->comment('是否降低既有额度');
                $table->string('status', 20)->comment('发布状态');
                $table->text('snapshot_json')->comment('额度配置快照');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['status', 'version'], 'platform_mqtt_quota_revision_status');
            });
        }
        if (!$schema->hasTable('platform_mqtt_quota_node_state')) {
            $schema->create('platform_mqtt_quota_node_state', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('额度版本在各节点上的生效进度');
                $table->string('revision_id', 32)->comment('对应发布版本的稳定标识');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->bigInteger('applied_version')->comment('节点已生效的发布代次');
                $table->string('state', 20)->comment('节点对该版本的生效状态');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['revision_id', 'node_id']);
                $table->foreign('revision_id', 'platform_mqtt_quota_revision', 'id');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_quota_node_state');
        $schema->dropIfExists('platform_mqtt_quota_revision');
    }
};
