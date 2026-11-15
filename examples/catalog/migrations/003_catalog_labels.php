<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

return new class extends SchemaMigration {
    public function description(): string
    {
        return '创建标签与租户商品关联';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('catalog_labels')) {
            $schema->create('catalog_labels', static function (Blueprint $table): void {
                $table->increments('id');
                $table->string('tenant_id', 40);
                $table->string('code', 50);
                $table->unique(['tenant_id', 'code'], 'catalog_label_identity');
            });
        }
        if (!$schema->hasTable('catalog_product_labels')) {
            $schema->create('catalog_product_labels', static function (Blueprint $table): void {
                $table->string('tenant_id', 40);
                $table->integer('product_id');
                $table->integer('label_id');
                $table->primary(['tenant_id', 'product_id', 'label_id']);
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('catalog_product_labels');
        $schema->dropIfExists('catalog_labels');
    }
};
