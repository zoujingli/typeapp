<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存已校验 CRL、粘性序列号与平台直接吊销
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存已校验 CRL、粘性序列号与平台直接吊销';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_access_crl')) {
            $schema->create('platform_mqtt_access_crl', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('受信 CA 已接受的证书吊销清单');
                $table->string('ca_id', 32)->comment('对应受信 CA');
                $table->text('pem')->comment('已接受的 CRL PEM');
                $table->bigInteger('this_update')->comment('CRL 本次更新时间，UTC Unix 秒');
                $table->bigInteger('next_update')->comment('CRL 下次更新时间，UTC Unix 秒');
                $table->text('url')->comment('CRL 分发地址');
                $table->integer('fetch_interval')->comment('拉取间隔，秒');
                $table->string('fetch_status', 20)->comment('最近一次拉取状态');
                $table->string('fetch_error', 100)->comment('最近一次拉取失败原因；成功时为空');
                $table->bigInteger('fetched_at')->comment('最近一次拉取时间，UTC Unix 秒');
                $table->bigInteger('accepted_at')->comment('最近一次接受 CRL 的时间，UTC Unix 秒');
                $table->bigInteger('fetch_due_at')->comment('下次应拉取时间，UTC Unix 秒');
                $table->integer('configured')->comment('是否已配置 CRL 分发');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['ca_id']);
                $table->foreign('ca_id', 'platform_mqtt_access_ca', 'id');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_crl_serial')) {
            $schema->create('platform_mqtt_access_crl_serial', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('CRL 中已吊销的证书序列号');
                $table->string('ca_id', 32)->comment('对应受信 CA');
                $table->string('serial', 64)->comment('被吊销的证书序列号');
                $table->primary(['ca_id', 'serial']);
                $table->foreign('ca_id', 'platform_mqtt_access_ca', 'id');
            });
        }
        if (!$schema->hasTable('platform_mqtt_access_platform_serial')) {
            $schema->create('platform_mqtt_access_platform_serial', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('平台直接吊销、不依赖 CRL 拉取的证书序列号');
                $table->string('tenant_key', 32)->comment('吊销所属 Tenant；平台级使用固定键');
                $table->string('serial', 64)->comment('被平台直接吊销的证书序列号');
                $table->string('actor_id', 32)->comment('发起本次变更的真实操作者标识');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['tenant_key', 'serial']);
            });
        }
        if (!$schema->hasColumn('platform_mqtt_access_certificate', 'serial')) {
            $schema->table('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->string('serial', 64)->default('')->comment('证书序列号，用于对照吊销清单');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_access_platform_serial');
        $schema->dropIfExists('platform_mqtt_access_crl_serial');
        $schema->dropIfExists('platform_mqtt_access_crl');
        if ($schema->hasColumn('platform_mqtt_access_certificate', 'serial')) {
            $schema->table('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->dropColumn('serial');
            });
        }
    }
};
