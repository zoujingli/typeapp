<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

return new class extends SchemaMigration {
    public function description(): string
    {
        return '创建事务消息意图与消费凭据';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('catalog_outbox')) {
            return;
        }
        $schema->create('catalog_outbox', static function (Blueprint $table): void {
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
            $table->index(['state', 'lease_until', 'created_at'], 'catalog_outbox_pending');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('catalog_outbox');
    }
};
