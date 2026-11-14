<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存接入主体、凭据代次、Topic 授权、发布版本与节点生效
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存接入主体、凭据代次、Topic 授权、发布版本与节点生效';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_access_principal')) {
            $schema->create('platform_mqtt_access_principal', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('设备或接入应用的 MQTT 主体');
                $table->string('id', 32)->comment('主体稳定标识，与设备或接入应用标识相同');
                $table->string('tenant_id', 32)->nullable()->comment('所属 Tenant；平台级主体为空');
                $table->string('name', 100)->comment('业务显示名称');
                $table->integer('enabled')->comment('当前是否允许接入');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['tenant_id', 'id'], 'platform_mqtt_access_principal_tenant');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_credential')) {
            $schema->create('platform_mqtt_access_credential', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('MQTT 主体的口令凭据及代次');
                $table->string('id', 32)->comment('凭据稳定标识');
                $table->string('principal_id', 32)->comment('所属 MQTT 主体');
                $table->string('login', 100)->unique()->comment('规范化登录名，设备与接入应用分域');
                $table->string('secret_hash', 64)->comment('口令摘要，不保存明文');
                $table->integer('credential_version')->comment('凭据代次，换钥后递增');
                $table->string('status', 20)->comment('凭据状态：active 或 revoked');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('revoked_at')->nullable()->comment('吊销时间，UTC Unix 秒；未吊销为空');
                $table->primary(['id']);
                $table->index(['principal_id', 'status'], 'platform_mqtt_access_credential_principal');
                $table->foreign('principal_id', 'platform_mqtt_access_principal', 'id');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_grant')) {
            $schema->create('platform_mqtt_access_grant', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('MQTT 主体对 Topic 的发布与订阅能力');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('principal_id', 32)->comment('被授权的 MQTT 主体');
                $table->string('topic', 200)->comment('允许访问的 Topic');
                $table->integer('publish')->comment('是否允许向该 Topic 发布');
                $table->integer('subscribe')->comment('是否允许订阅该 Topic');
                $table->integer('max_qos')->comment('允许的最高 QoS');
                $table->primary(['id']);
                $table->index(['principal_id', 'topic'], 'platform_mqtt_access_grant_principal');
                $table->foreign('principal_id', 'platform_mqtt_access_principal', 'id');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_revision')) {
            $schema->create('platform_mqtt_access_revision', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('接入授权的发布版本');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->bigInteger('version')->unique()->comment('发布代次，单调递增');
                $table->string('actor_id', 32)->comment('发起本次变更的真实操作者标识');
                $table->string('actor_realm', 16)->comment('操作者身份域，platform 或 system');
                $table->string('tenant_id', 32)->nullable()->comment('本次发布影响的 Tenant；平台级发布为空');
                $table->integer('tightening')->comment('是否收紧既有授权，收紧发布不能直接回滚');
                $table->string('status', 20)->comment('发布状态');
                $table->text('snapshot_json')->comment('本次发布的完整配置快照');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['status', 'version'], 'platform_mqtt_access_revision_status');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_node_state')) {
            $schema->create('platform_mqtt_access_node_state', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('接入授权在各节点上的生效进度');
                $table->string('revision_id', 32)->comment('对应发布版本的稳定标识');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->bigInteger('applied_version')->comment('节点已生效的发布代次');
                $table->string('state', 20)->comment('节点对该版本的生效状态');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['revision_id', 'node_id']);
                $table->foreign('revision_id', 'platform_mqtt_access_revision', 'id');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_invalidation')) {
            $schema->create('platform_mqtt_access_invalidation', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('待 Broker 隔离的接入身份');
                $table->string('id', 32)->comment('隔离意图的稳定标识');
                $table->text('client_id')->comment('需要断开的 MQTT Client ID');
                $table->string('principal', 100)->comment('Broker 侧待隔离主体');
                $table->string('actor', 32)->comment('发起隔离的操作者');
                $table->text('access_identity')->comment('隔离所依据的接入身份');
                $table->bigInteger('requested_at')->comment('发起时间，UTC Unix 秒');
                $table->bigInteger('completed_at')->nullable()->comment('节点完成隔离的时间，UTC Unix 秒；未完成为空');
                $table->string('node_id', 64)->nullable()->comment('执行隔离的节点；尚未分派为空');
                $table->primary(['id']);
                $table->index(['completed_at', 'requested_at', 'id'], 'platform_mqtt_access_invalidation_pending');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_access_invalidation');
        $schema->dropIfExists('platform_mqtt_access_node_state');
        $schema->dropIfExists('platform_mqtt_access_revision');
        $schema->dropIfExists('platform_mqtt_access_grant');
        $schema->dropIfExists('platform_mqtt_access_credential');
        $schema->dropIfExists('platform_mqtt_access_principal');
    }
};
