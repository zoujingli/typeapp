<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 按 Client ID 计调试名额，同一身份重连不额外占用
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '按 Client ID 计调试名额，同一身份重连不额外占用';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_debug_quota_lock')) {
            $schema->create('platform_mqtt_debug_quota_lock', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('调试名额分配的互斥锁，只有 default 一行');
                $table->string('id', 32)->comment('固定锁标识，值为 default');
                $table->primary(['id']);
            });
            $schema->insert('platform_mqtt_debug_quota_lock', [['id' => 'default']]);
        }
        if (!$schema->hasTable('platform_mqtt_debug_occupancy')) {
            $schema->create('platform_mqtt_debug_occupancy', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('按 Client ID 占用的调试名额，同一身份重连不额外占用');
                $table->string('client_id', 32)->comment('占用名额的调试 Client ID');
                $table->string('credential_id', 32)->comment('对应调试凭据');
                $table->string('actor_id', 32)->comment('占用名额的操作者');
                $table->string('node_id', 64)->comment('承载调试连接的节点');
                $table->bigInteger('occupied_at')->comment('占用开始时间，UTC Unix 秒');
                $table->primary(['client_id']);
                $table->index(['actor_id', 'client_id'], 'platform_mqtt_debug_occupancy_actor');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_debug_occupancy');
        $schema->dropIfExists('platform_mqtt_debug_quota_lock');
    }
};
