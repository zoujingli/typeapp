<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

return new class extends SchemaMigration {
    public function description(): string
    {
        return '商品增加可空备注';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasColumn('catalog_products', 'note')) {
            $schema->table('catalog_products', static function (Blueprint $table): void {
                $table->string('note', 200)->nullable()->comment('商品备注');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->table('catalog_products', static function (Blueprint $table): void {
            $table->dropColumn('note');
        });
    }
};
