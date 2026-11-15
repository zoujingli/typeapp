<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

return new class extends SchemaMigration {
    public function description(): string
    {
        return '创建商品通知消费效果';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('catalog_deliveries')) {
            return;
        }
        $schema->create('catalog_deliveries', static function (Blueprint $table): void {
            $table->string('id', 128);
            $table->integer('product_id');
            $table->primary(['id']);
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('catalog_deliveries');
    }
};
