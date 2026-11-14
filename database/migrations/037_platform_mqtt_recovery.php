<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 保存Broker恢复核对进度并为接入对象增加核对标记
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '保存Broker恢复核对进度并为接入对象增加核对标记';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_mqtt_recovery')) {
            $schema->create('platform_mqtt_recovery', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('Broker 接入事实的恢复核对进度');
                $table->string('id', 32)->comment('恢复操作的稳定标识');
                $table->integer('slot')->nullable()->unique()->comment('当前活动槽位；空值表示历史记录');
                $table->string('actor', 32)->comment('发起恢复的维护者标识');
                $table->string('proof', 200)->comment('已完成隔离依据的受控引用');
                $table->string('state', 20)->comment('恢复阶段');
                $table->integer('kind_index')->comment('当前主体种类索引');
                $table->string('cursor', 32)->comment('当前主体种类内的有序游标');
                $table->bigInteger('started_at')->comment('恢复开始时间，UTC Unix 秒');
                $table->bigInteger('reviewed_at')->nullable()->comment('最近一次核对时间，UTC Unix 秒；尚未核对为空');
                $table->bigInteger('completed_at')->nullable()->comment('恢复完成时间，UTC Unix 秒；未完成为空');
                $table->string('review_sha256', 64)->comment('当前核对清单摘要，不保存清单原文');
                $table->primary(['id']);
            });
        }
        if (!$schema->hasTable('platform_mqtt_recovery_subject')) {
            $schema->create('platform_mqtt_recovery_subject', static function (Blueprint $table): void {
                $table->charset('utf8mb4', 'utf8mb4_bin');
                $table->comment('Broker 恢复中待核对的接入主体');
                $table->string('recovery_id', 32)->comment('所属恢复操作');
                $table->string('kind', 20)->comment('主体种类');
                $table->string('subject_id', 32)->comment('被核对对象的稳定标识');
                $table->string('sha256', 64)->comment('主体事实摘要');
                $table->integer('approved')->default(0)->comment('维护者是否已确认该主体');
                $table->primary(['recovery_id', 'kind', 'subject_id']);
                $table->foreign('recovery_id', 'platform_mqtt_recovery', 'id');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_access_principal', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_principal', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_access_credential', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_credential', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_access_certificate', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_access_ca', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_ca', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_debug_credential', 'recovery_verified')) {
            $schema->table('platform_mqtt_debug_credential', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_access_revision', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_revision', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_quota_revision', 'recovery_verified')) {
            $schema->table('platform_mqtt_quota_revision', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
        if (!$schema->hasColumn('platform_mqtt_runtime_revision', 'recovery_verified')) {
            $schema->table('platform_mqtt_runtime_revision', static function (Blueprint $table): void {
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_mqtt_recovery_subject');
        $schema->dropIfExists('platform_mqtt_recovery');
        if ($schema->hasColumn('platform_mqtt_runtime_revision', 'recovery_verified')) {
            $schema->table('platform_mqtt_runtime_revision', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_quota_revision', 'recovery_verified')) {
            $schema->table('platform_mqtt_quota_revision', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_access_revision', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_revision', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_debug_credential', 'recovery_verified')) {
            $schema->table('platform_mqtt_debug_credential', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_access_ca', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_ca', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_access_certificate', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_certificate', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_access_credential', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_credential', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
        if ($schema->hasColumn('platform_mqtt_access_principal', 'recovery_verified')) {
            $schema->table('platform_mqtt_access_principal', static function (Blueprint $table): void {
                $table->dropColumn('recovery_verified');
            });
        }
    }
};
