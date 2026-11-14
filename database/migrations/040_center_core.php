<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 建立 Center 设备、注册资格、接入应用与转配业务表
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '建立 Center 设备、注册资格、接入应用与转配业务表';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('center_product')) {
            $schema->create('center_product', static function (Blueprint $table): void {
                $table->comment('客户设备分类资料');
                $table->string('id', 32)->comment('设备分类的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('name', 100)->comment('业务显示名称');
                $table->text('description')->comment('供管理人员阅读的说明');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'id'], 'center_product_tenant_id_id_unique');
                $table->index(['tenant_id', 'created_at', 'id'], 'center_product_tenant_time');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
            });
        }
        if (!$schema->hasTable('center_device')) {
            $schema->create('center_device', static function (Blueprint $table): void {
                $table->comment('客户当前使用的原始通信设备');
                $table->string('id', 32)->comment('设备的全局稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('product_id', 32)->nullable()->comment('设备所属分类标识；未分类时为空');
                $table->string('ownership_id', 32)->unique()->comment('授权或操作绑定的设备使用关系');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('lifecycle', 20)->comment('设备管理生命周期，与连接观察状态分别维护');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->string('transfer_id', 32)->nullable()->comment('当前转配流程标识；无进行中流程时为空');
                $table->integer('transfer_frozen')->default(0)->comment('当前使用关系是否因转配流程冻结');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'id'], 'center_device_tenant_id_id_unique');
                $table->index(['tenant_id', 'created_at', 'id'], 'center_device_tenant_time');
                $table->index(['created_at', 'id'], 'center_device_global_time');
                $table->index(['tenant_id', 'product_id'], 'center_device_product');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
                $table->foreign(['tenant_id', 'product_id'], 'center_product', ['tenant_id', 'id']);
            });
        }
        if (!$schema->hasTable('center_device_ownership')) {
            $schema->create('center_device_ownership', static function (Blueprint $table): void {
                $table->comment('设备在客户中的使用关系阶段');
                $table->string('id', 32)->comment('设备一次使用关系的稳定标识');
                $table->string('device_id', 32)->comment('设备的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->bigInteger('started_at')->comment('本次设备使用关系的开始时间，UTC Unix 秒');
                $table->bigInteger('ended_at')->nullable()->comment('使用关系结束时间，UTC Unix 秒；尚未结束时为空');
                $table->bigInteger('expires_at')->nullable()->comment('有效截止时间，UTC Unix 秒');
                $table->text('expiry_context')->nullable()->comment('使用期限操作的审计身份快照');
                $table->integer('access_started')->default(0)->comment('是否已有实际通信接入历史');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'id'], 'center_device_ownership_tenant_id_id_unique');
                $table->index(['tenant_id', 'device_id', 'started_at'], 'center_ownership_history');
                $table->index(['ended_at', 'expires_at', 'id'], 'center_ownership_expiry');
                $table->foreign('device_id', 'center_device', 'id');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
            });
        }
        if (!$schema->hasTable('center_device_credential')) {
            $schema->create('center_device_credential', static function (Blueprint $table): void {
                $table->comment('设备当前接入凭据');
                $table->string('id', 32)->comment('凭据稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('device_id', 32)->comment('设备的稳定标识');
                $table->string('ownership_id', 32)->comment('凭据对应的设备使用关系');
                $table->string('secret_hash', 64)->comment('凭据验证散列，不进入公开投影');
                $table->string('status', 20)->comment('凭据状态');
                $table->integer('recovery_verified')->default(1)->comment('凭据事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('revoked_at')->nullable()->comment('吊销时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['tenant_id', 'device_id', 'status'], 'center_device_credential_device');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
                $table->foreign('device_id', 'center_device', 'id');
                $table->foreign(['tenant_id', 'ownership_id'], 'center_device_ownership', ['tenant_id', 'id']);
            });
        }
        if (!$schema->hasTable('center_broker_observation')) {
            $schema->create('center_broker_observation', static function (Blueprint $table): void {
                $table->comment('客户中心看到的 Broker 节点存活观察');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->string('run_id', 32)->comment('节点本次运行代次');
                $table->bigInteger('observed_at')->comment('最近一次观察时间，UTC Unix 秒');
                $table->bigInteger('expires_at')->comment('观察失效时间，UTC Unix 秒');
                $table->primary(['node_id']);
            });
        }
        if (!$schema->hasTable('center_device_connection')) {
            $schema->create('center_device_connection', static function (Blueprint $table): void {
                $table->comment('设备当前连接观察投影');
                $table->string('device_id', 32)->comment('设备的稳定标识，也是本投影主键');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('ownership_id', 32)->comment('观察对应的设备使用关系');
                $table->string('owner_id', 32)->nullable()->comment('当前连接资源所有者标识');
                $table->string('node_id', 64)->nullable()->comment('产生观察的 MQTT 节点标识');
                $table->string('run_id', 32)->nullable()->comment('节点运行代次');
                $table->string('status', 20)->comment('当前连接观察状态');
                $table->bigInteger('observed_at')->nullable()->comment('最近一次观察时间，UTC Unix 秒');
                $table->primary(['device_id']);
                $table->foreign('tenant_id', 'platform_tenant', 'id');
                $table->foreign('device_id', 'center_device', 'id');
                $table->foreign(['tenant_id', 'ownership_id'], 'center_device_ownership', ['tenant_id', 'id']);
            });
        }
        if (!$schema->hasTable('center_authorization_invalidation')) {
            $schema->create('center_authorization_invalidation', static function (Blueprint $table): void {
                $table->comment('设备凭据撤权及 Broker 隔离回执');
                $table->string('device_id', 32)->comment('设备的稳定标识，也是本记录主键');
                $table->string('id', 32)->unique()->comment('撤权意图的稳定标识');
                $table->string('principal', 65)->comment('Broker 侧待隔离主体标识');
                $table->string('actor_id', 32)->comment('发起撤权的真实操作者标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('actor_realm', 16)->comment('发起撤权的身份域');
                $table->text('context_json')->comment('撤权时保存的身份与来源快照，不进入公开投影');
                $table->bigInteger('requested_at')->comment('撤权意图登记时间，UTC Unix 秒');
                $table->bigInteger('completed_at')->nullable()->comment('Broker 完成隔离的时间，UTC Unix 秒；尚未完成时为空');
                $table->string('node_id', 64)->nullable()->comment('完成隔离的 Broker 节点标识');
                $table->primary(['device_id']);
                $table->index(['completed_at', 'requested_at', 'id'], 'center_authorization_pending');
                $table->foreign('device_id', 'center_device', 'id');
            });
        }
        if (!$schema->hasTable('center_device_enrollment')) {
            $schema->create('center_device_enrollment', static function (Blueprint $table): void {
                $table->comment('平台签发的设备注册资格');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('name', 100)->comment('业务显示名称');
                $table->integer('capacity')->comment('本批注册资格允许注册的设备总数');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->integer('registered_count')->default(0)->comment('本批注册资格已使用的设备数量');
                $table->bigInteger('register_expires_at')->comment('允许新设备注册的截止时间，UTC Unix 秒');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->string('secret_hash', 64)->comment('注册码散列，原始码仅在签发或重置时交付');
                $table->string('request_hash', 64)->comment('创建请求内容摘要，用于拒绝幂等标识冲突');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'id'], 'center_device_enrollment_tenant_id_id_unique');
                $table->index(['tenant_id', 'created_at', 'id'], 'center_enrollments_tenant_time');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
                $table->check('capacity > 0 AND registered_count >= 0 AND registered_count <= capacity');
            });
        }
        if (!$schema->hasTable('center_device_enrollment_binding')) {
            $schema->create('center_device_enrollment_binding', static function (Blueprint $table): void {
                $table->comment('注册资格与设备使用关系绑定事实');
                $table->string('ownership_id', 32)->comment('设备使用关系标识，也是本模型的稳定主键');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('device_id', 32)->comment('设备的稳定标识');
                $table->string('enrollment_id', 32)->comment('注册资格标识');
                $table->bigInteger('registered_at')->comment('注册完成时间，UTC Unix 秒');
                $table->string('request_id', 32)->nullable()->unique()->comment('幂等请求标识');
                $table->string('request_hash', 64)->nullable()->comment('注册请求摘要');
                $table->primary(['ownership_id']);
                $table->unique(['enrollment_id', 'device_id'], 'center_device_enrollment_binding_enrollment_id_device_id_unique');
                $table->index(['tenant_id', 'enrollment_id', 'registered_at', 'device_id'], 'center_enrollment_bindings_grant');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
                $table->foreign('device_id', 'center_device', 'id');
                $table->foreign(['tenant_id', 'enrollment_id'], 'center_device_enrollment', ['tenant_id', 'id']);
                $table->foreign(['tenant_id', 'ownership_id'], 'center_device_ownership', ['tenant_id', 'id']);
            });
        }
        if (!$schema->hasTable('center_application')) {
            $schema->create('center_application', static function (Blueprint $table): void {
                $table->comment('客户外部系统接入应用身份');
                $table->string('id', 32)->charset('utf8mb4', 'utf8mb4_bin')->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('name', 100)->comment('业务显示名称');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->string('credential_id', 32)->charset('utf8mb4', 'utf8mb4_bin')->unique()->comment('当前接入凭据的稳定标识');
                $table->integer('key_version')->default(1)->comment('接入应用密钥代次');
                $table->integer('access_version')->comment('通信授权发布代次');
                $table->string('request_hash', 64)->comment('创建请求内容摘要，用于拒绝幂等标识冲突');
                $table->integer('access_started')->default(0)->comment('已发出有效认证结论，不表示连接在线；防止遗留持久会话被误判为从未使用');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'id'], 'center_application_tenant_id_id_unique');
                $table->index(['tenant_id', 'created_at', 'id'], 'center_application_tenant_time');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
                $table->foreign('id', 'platform_mqtt_access_principal', 'id');
                $table->foreign('credential_id', 'platform_mqtt_access_credential', 'id');
            });
        }
        if (!$schema->hasTable('center_application_grant')) {
            $schema->create('center_application_grant', static function (Blueprint $table): void {
                $table->comment('接入应用对设备使用关系的通信授权');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('application_id', 32)->charset('utf8mb4', 'utf8mb4_bin')->comment('取得通信授权的接入应用标识');
                $table->string('device_id', 32)->comment('设备的稳定标识');
                $table->string('ownership_id', 32)->comment('授权或操作绑定的设备使用关系');
                $table->integer('receive')->comment('是否允许接收设备上行消息');
                $table->integer('send')->comment('是否允许向设备发送下行消息');
                $table->integer('subscribe')->comment('是否允许订阅设备上行 Topic，须同时具有接收资格');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['application_id', 'ownership_id'], 'center_application_grant_application_id_ownership_id_unique');
                $table->index(['tenant_id', 'application_id', 'created_at', 'id'], 'center_application_grant_scope');
                $table->index(['device_id', 'ownership_id'], 'center_application_grant_device');
                $table->foreign(['tenant_id', 'application_id'], 'center_application', ['tenant_id', 'id']);
                $table->foreign('device_id', 'center_device', 'id');
                $table->foreign('ownership_id', 'center_device_ownership', 'id');
            });
        }
        if (!$schema->hasTable('center_device_reassignment')) {
            $schema->create('center_device_reassignment', static function (Blueprint $table): void {
                $table->comment('设备跨客户转配流程');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('device_id', 32)->comment('设备的稳定标识');
                $table->string('device_name', 100)->comment('发起流程时保留的设备名称');
                $table->string('source_tenant_id', 32)->comment('流程来源 Tenant 标识');
                $table->string('target_tenant_id', 32)->nullable()->comment('本次操作的目标 Tenant 标识');
                $table->string('ownership_id', 32)->comment('授权或操作绑定的设备使用关系');
                $table->string('new_ownership_id', 32)->nullable()->comment('目标客户确认后建立的新使用关系标识');
                $table->string('kind', 16)->comment('业务记录类型');
                $table->string('status', 24)->comment('流程的实际处理阶段');
                $table->integer('request_version')->comment('发起流程时核对的预期版本');
                $table->text('source_context')->comment('发起操作的身份与授权快照');
                $table->integer('requires_store')->comment('是否必须取得持久存储隔离证明');
                $table->string('isolation_id', 32)->nullable()->comment('关联的通信隔离操作标识');
                $table->integer('access_version')->nullable()->comment('通信授权发布代次');
                $table->text('retained_json')->comment('旧归属阶段保留消息核验结果的 JSON');
                $table->string('accept_id', 32)->nullable()->comment('目标接收请求的幂等标识');
                $table->integer('accept_version')->nullable()->comment('接收请求核对的预期版本');
                $table->text('accept_context')->nullable()->comment('目标客户接收操作的身份快照');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['source_tenant_id', 'created_at', 'id'], 'center_reassignments_source');
                $table->index(['target_tenant_id', 'created_at', 'id'], 'center_reassignments_target');
                $table->foreign('device_id', 'center_device', 'id');
                $table->foreign('source_tenant_id', 'platform_tenant', 'id');
                $table->foreign('target_tenant_id', 'platform_tenant', 'id');
                $table->foreign('ownership_id', 'center_device_ownership', 'id');
                $table->foreign('new_ownership_id', 'center_device_ownership', 'id');
            });
        }
        if (!$schema->hasTable('center_runtime_metric')) {
            $schema->create('center_runtime_metric', static function (Blueprint $table): void {
                $table->comment('MQTT 节点运行观察投影');
                $table->integer('slot')->check('slot >= 0 AND slot < 64')->comment('观察槽位，范围为 0 至 63');
                $table->string('kind', 16)->comment('观察类型，目前固定为 broker');
                $table->string('node_id', 64)->comment('MQTT 节点标识');
                $table->string('run_id', 32)->comment('节点运行代次');
                $table->bigInteger('observed_at')->comment('最近一次采样时间，UTC Unix 秒');
                $table->text('metrics_json')->comment('当前采样的 JSON 数值对象');
                $table->text('previous_json')->nullable()->comment('同一运行代次的上一份采样，换代时为空');
                $table->primary(['slot']);
                $table->unique(['kind', 'node_id'], 'center_runtime_metric_kind_node_id_unique');
            });
        }
        if (!$schema->hasTable('center_recovery')) {
            $schema->create('center_recovery', static function (Blueprint $table): void {
                $table->comment('客户中心恢复操作的进度');
                $table->string('id', 32)->comment('恢复操作的稳定标识');
                $table->integer('slot')->nullable()->unique()->comment('当前活动槽位，1 表示唯一活动恢复，空值表示历史记录');
                $table->string('actor', 32)->comment('发起恢复操作的维护者标识');
                $table->string('proof', 200)->comment('已完成隔离依据的受控引用');
                $table->string('state', 20)->comment('恢复阶段：isolating、reviewing、restoring 或 ready');
                $table->integer('kind_index')->comment('当前主体种类索引');
                $table->string('cursor', 32)->comment('当前主体种类内的有序游标');
                $table->bigInteger('started_at')->comment('恢复开始时间，UTC Unix 秒');
                $table->bigInteger('reviewed_at')->nullable()->comment('最近一次核对批次时间，UTC Unix 秒');
                $table->bigInteger('completed_at')->nullable()->comment('恢复完成时间，UTC Unix 秒');
                $table->string('review_sha256', 64)->comment('当前核对清单摘要，不保存清单原文');
                $table->primary(['id']);
            });
        }
        if (!$schema->hasTable('center_recovery_subject')) {
            $schema->create('center_recovery_subject', static function (Blueprint $table): void {
                $table->comment('客户中心恢复中待核对的业务主体');
                $table->string('recovery_id', 32)->comment('所属恢复操作');
                $table->string('kind', 20)->comment('主体种类');
                $table->string('subject_id', 32)->comment('被核对对象的稳定标识');
                $table->string('sha256', 64)->comment('主体事实摘要');
                $table->integer('approved')->default(0)->comment('维护者是否已确认该主体');
                $table->primary(['recovery_id', 'kind', 'subject_id']);
                $table->foreign('recovery_id', 'center_recovery', 'id');
            });
        }
        if (!$schema->hasTable('center_registration_attempt')) {
            $schema->create('center_registration_attempt', static function (Blueprint $table): void {
                $table->comment('公开设备注册入口来源限流窗口');
                $table->string('source_hash', 64)->comment('已规范化客户端来源的 SHA-256 摘要');
                $table->bigInteger('window_started')->comment('当前限流窗口开始时间，UTC Unix 秒');
                $table->integer('attempts')->check('attempts >= 1 AND attempts <= 60')->comment('当前来源在窗口内的尝试次数');
                $table->primary(['source_hash']);
                $table->index(['window_started'], 'center_registration_window');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('center_registration_attempt');
        $schema->dropIfExists('center_recovery_subject');
        $schema->dropIfExists('center_recovery');
        $schema->dropIfExists('center_runtime_metric');
        $schema->dropIfExists('center_device_reassignment');
        $schema->dropIfExists('center_application_grant');
        $schema->dropIfExists('center_application');
        $schema->dropIfExists('center_device_enrollment_binding');
        $schema->dropIfExists('center_device_enrollment');
        $schema->dropIfExists('center_authorization_invalidation');
        $schema->dropIfExists('center_device_connection');
        $schema->dropIfExists('center_broker_observation');
        $schema->dropIfExists('center_device_credential');
        $schema->dropIfExists('center_device_ownership');
        $schema->dropIfExists('center_device');
        $schema->dropIfExists('center_product');
    }
};
