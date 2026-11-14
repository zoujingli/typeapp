<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存需运维重启才生效的监听、I/O 与证书路径版本及节点加载身份
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存需运维重启才生效的监听、I/O 与证书路径版本及节点加载身份';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_runtime_revision')) {
            $schema->create('platform_mqtt_runtime_revision', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('需重启才生效的监听、I/O 与证书路径版本');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->bigInteger('version')->unique()->comment('发布代次，单调递增');
                $table->string('actor_id', 32)->comment('发起本次变更的真实操作者标识');
                $table->string('actor_realm', 16)->comment('操作者身份域，platform 或 system');
                $table->integer('tightening')->comment('是否收紧运行边界，收紧发布不能直接回滚');
                $table->string('status', 20)->comment('发布状态');
                $table->text('snapshot_json')->comment('运行配置快照');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['status', 'version'], 'platform_mqtt_runtime_revision_status');
            });
        }
        if (!$schema->hasTable('platform_mqtt_runtime_node_state')) {
            $schema->create('platform_mqtt_runtime_node_state', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('运行版本在各节点上的加载进度');
                $table->string('revision_id', 32)->comment('对应发布版本的稳定标识');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->bigInteger('applied_version')->comment('节点已加载的运行代次');
                $table->string('state', 20)->comment('节点加载状态');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['revision_id', 'node_id']);
                $table->foreign('revision_id', 'platform_mqtt_runtime_revision', 'id');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_runtime_node_state');
        $schema->dropIfExists('platform_mqtt_runtime_revision');
    }
};
