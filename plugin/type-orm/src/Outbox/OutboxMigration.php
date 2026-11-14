<?php

declare(strict_types=1);

namespace Type\Orm\Outbox;

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 用 Schema 建立事务消息意图表，表名由调用方限定为安全标识符。
 */
final class OutboxMigration extends SchemaMigration
{
    /**
     * 表名由调用方限定为安全标识符，版本也由调用方传入。
     */
    public function __construct(private string $version, private string $table) {}

    /**
     * 使用构造时传入的版本，不从文件名绑定。
     */
    public function version(): string
    {
        return $this->version;
    }

    /**
     * 说明会进入迁移记录，并参与校验和。
     */
    public function description(): string
    {
        return '创建事务消息意图与消费凭据';
    }

    /**
     * 表已存在时不再建表。租约和消费凭据与意图放在同一张表。
     */
    public function up(Schema $schema): void
    {
        $name = $this->table;
        if ($schema->hasTable($name)) {
            return;
        }
        $schema->create($name, static function (Blueprint $table) use ($name): void {
            $table->string('id', 128);
            $table->string('topic', 128);
            $table->integer('version');
            $table->text('payload');
            $table->text('context');
            $table->string('fingerprint', 64);
            $table->string('state', 16);
            $table->string('token', 64);
            $table->bigInteger('lease_until');
            $table->integer('attempts');
            $table->bigInteger('created_at');
            $table->bigInteger('published_at')->nullable();
            $table->text('accepted_receipt')->nullable();
            $table->text('consumed_receipt')->nullable();
            $table->bigInteger('consumed_at')->nullable();
            $table->text('replay_reason')->nullable();
            $table->primary(['id']);
            $table->index(['state', 'lease_until', 'created_at'], $name . '_pending');
        });
    }

    /**
     * 删除整张意图表。
     */
    public function down(Schema $schema): void
    {
        $schema->dropIfExists($this->table);
    }
}
