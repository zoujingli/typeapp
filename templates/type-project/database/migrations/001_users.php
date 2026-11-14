<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 创建用户、乐观锁与软删除字段
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '创建用户、乐观锁与软删除字段';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('users')) {
            return;
        }
        $schema->create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 100)->comment('显示名称');
            $table->integer('age')->comment('年龄');
            $table->string('email', 255)->nullable()->comment('联系邮箱');
            $table->integer('version')->default(1)->comment('并发版本');
            $table->dateTime('deleted_at')->nullable()->comment('软删除时间');
            $table->comment('用户');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('users');
    }
};
