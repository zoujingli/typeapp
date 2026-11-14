<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存登录会话绑定的 MQTT 调试短期凭据，不进入接入快照
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存登录会话绑定的 MQTT 调试短期凭据，不进入接入快照';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_debug_credential')) {
            $schema->create('platform_mqtt_debug_credential', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('登录会话绑定的 MQTT 调试短期凭据，不进入接入快照');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('principal_id', 32)->unique()->comment('调试使用的临时主体，不代表正式设备或接入应用');
                $table->string('tenant_id', 32)->nullable()->comment('调试所属 Tenant；平台调试可为空');
                $table->string('actor_id', 32)->comment('发起本次变更的真实操作者标识');
                $table->string('actor_realm', 16)->comment('操作者身份域，platform 或 system');
                $table->string('session_id', 64)->comment('绑定的登录会话');
                $table->string('support_id', 32)->nullable()->comment('平台模拟登录的交接标识；普通调试为空');
                $table->string('login', 100)->unique()->comment('调试登录名');
                $table->string('secret_hash', 64)->comment('调试口令摘要，不保存明文');
                $table->integer('credential_version')->comment('调试凭据代次');
                $table->string('client_id', 32)->comment('调试连接使用的 Client ID');
                $table->string('subscribe_topic', 200)->comment('允许订阅的调试 Topic');
                $table->string('publish_topic', 200)->comment('允许发布的调试 Topic');
                $table->string('status', 20)->comment('调试凭据状态：active 或 revoked');
                $table->bigInteger('expires_at')->comment('调试凭据失效时间，UTC Unix 秒');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('revoked_at')->nullable()->comment('吊销时间，UTC Unix 秒；未吊销为空');
                $table->primary(['id']);
                $table->index(['session_id', 'status'], 'platform_mqtt_debug_credential_session');
                $table->index(['expires_at', 'status'], 'platform_mqtt_debug_credential_expiry');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_debug_credential');
    }
};
