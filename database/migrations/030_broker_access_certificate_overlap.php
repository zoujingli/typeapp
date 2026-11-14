<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 为客户端证书保存换证重叠截止时间
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '为客户端证书保存换证重叠截止时间';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasColumn('platform_mqtt_access_certificate', 'overlap_until')) {
            $schema->table('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->bigInteger('overlap_until')->default(0)->comment('换证重叠截止时间，UTC Unix 秒；0 表示不允许旧证继续使用');
            });
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasColumn('platform_mqtt_access_certificate', 'overlap_until')) {
            $schema->table('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->dropColumn('overlap_until');
            });
        }
    }
};
