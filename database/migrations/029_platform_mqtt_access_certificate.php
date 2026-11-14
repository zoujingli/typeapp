<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存受信 CA 与客户端证书到主体的绑定
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存受信 CA 与客户端证书到主体的绑定';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_access_ca')) {
            $schema->create('platform_mqtt_access_ca', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('MQTT 客户端证书的受信 CA');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->nullable()->comment('所属 Tenant；平台共用 CA 为空');
                $table->string('fingerprint', 64)->comment('证书指纹');
                $table->string('subject', 256)->comment('证书主体可分辨名');
                $table->text('pem')->comment('CA 证书 PEM，不包含私钥');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['fingerprint'], 'platform_mqtt_access_ca_fingerprint');
                $table->index(['tenant_id', 'id'], 'platform_mqtt_access_ca_tenant');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_certificate')) {
            $schema->create('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('绑定到 MQTT 主体的客户端证书');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('principal_id', 32)->comment('证书所属 MQTT 主体');
                $table->string('fingerprint', 64)->comment('客户端证书指纹');
                $table->string('subject', 256)->comment('客户端证书主体可分辨名');
                $table->text('pem')->comment('客户端证书 PEM，不包含私钥');
                $table->bigInteger('not_after')->comment('证书失效时间，UTC Unix 秒');
                $table->integer('certificate_version')->comment('证书代次，换证后递增');
                $table->string('status', 20)->comment('证书状态：active 或 revoked');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('revoked_at')->nullable()->comment('吊销时间，UTC Unix 秒；未吊销为空');
                $table->primary(['id']);
                $table->unique(['fingerprint'], 'platform_mqtt_access_certificate_fingerprint');
                $table->index(['principal_id', 'status'], 'platform_mqtt_access_certificate_principal');
                $table->foreign('principal_id', 'platform_mqtt_access_principal', 'id');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_access_certificate');
        $schema->dropIfExists('platform_mqtt_access_ca');
    }
};
