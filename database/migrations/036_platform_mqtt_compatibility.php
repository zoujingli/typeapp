<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存管理库兼容代次与危险回退维护说明
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存管理库兼容代次与危险回退维护说明';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_compatibility')) {
            $schema->create('platform_mqtt_compatibility', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('管理库兼容代次与危险回退的维护说明');
                $table->string('id', 16)->comment('兼容记录标识');
                $table->integer('runtime_epoch')->comment('当前程序接受的运行代次');
                $table->integer('min_runtime_epoch')->comment('允许回退到的最低运行代次');
                $table->string('management_head', 64)->comment('管理库结构头摘要');
                $table->integer('store_ready')->comment('管理库是否已完成安装并可提供服务');
                $table->integer('rollback_allowed')->comment('是否允许危险回退');
                $table->text('rollback_reason')->comment('允许或拒绝回退的维护说明');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_compatibility');
    }
};
