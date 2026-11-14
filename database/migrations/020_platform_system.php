<?php

declare(strict_types=1);

use Type\Orm\Migration\SchemaMigration;
use Type\Orm\Schema\Blueprint;
use Type\Orm\Schema\Schema;

/**
 * 建立 Platform、System、Tenant 身份与会话基础表
 */
return new class extends SchemaMigration {
    public function description(): string
    {
        return '建立 Platform、System、Tenant 身份与会话基础表';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('platform_installation')) {
            $schema->create('platform_installation', static function (Blueprint $table): void {
                $table->comment('平台安装身份与模式版本');
                $table->integer('id')->check('id = 1')->comment('安装互斥槽位，固定为 1');
                $table->string('installation_id', 32)->unique()->comment('全新安装生成的稳定身份');
                $table->integer('schema_version')->comment('当前安装支持的数据结构版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['id']);
            });
        }
        if (!$schema->hasTable('platform_tenant')) {
            $schema->create('platform_tenant', static function (Blueprint $table): void {
                $table->comment('客户组织、登记域名与开通状态');
                $table->string('id', 32)->comment('客户不可变的 32 位小写十六进制标识');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('subdomain', 63)->nullable()->unique()->comment('登记的客户子域名前缀；未配置时为空');
                $table->string('request_id', 32)->unique()->comment('创建请求的幂等标识');
                $table->string('request_hash', 64)->comment('创建请求内容摘要，用于拒绝幂等标识冲突');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['id']);
            });
        }
        if (!$schema->hasTable('platform_setting')) {
            $schema->create('platform_setting', static function (Blueprint $table): void {
                $table->comment('平台品牌、主题及公共登录模板');
                $table->integer('id')->check('id IN (1, 2)')->comment('配置槽位：1 为平台设置，2 为公共登录及客户初始模板');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('official_url', 512)->comment('公开展示的官方网站地址');
                $table->string('description', 500)->comment('供管理人员阅读的说明');
                $table->string('logo_url', 512)->comment('品牌标识资源地址，空值采用缺省品牌');
                $table->string('timezone', 64)->comment('界面默认使用的 IANA 时区名称');
                $table->string('theme_mode', 8)->comment('默认主题模式：light、dark 或 auto');
                $table->string('theme_color', 16)->comment('默认主题主色，六位十六进制颜色');
                $table->string('theme_radius', 8)->comment('默认组件圆角配置');
                $table->string('layout_mode', 32)->comment('默认工作区布局模式');
                $table->integer('sidebar_collapsed')->default(0)->comment('侧栏默认折叠状态');
                $table->string('navigation_style', 16)->comment('默认导航外观');
                $table->integer('navigation_split')->default(1)->comment('混合导航是否拆分显示');
                $table->integer('breadcrumb_enable')->default(1)->comment('是否默认显示面包屑');
                $table->integer('breadcrumb_show_icon')->default(1)->comment('面包屑是否默认显示图标');
                $table->string('breadcrumb_style', 16)->comment('默认面包屑样式');
                $table->integer('tabbar_enable')->default(0)->comment('是否默认启用标签页');
                $table->string('tabbar_style', 16)->comment('默认标签页样式');
                $table->integer('footer_enable')->default(0)->comment('是否默认显示页脚');
                $table->integer('footer_fixed')->default(0)->comment('页脚是否默认固定');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
            });
        }
        if (!$schema->hasTable('system_setting')) {
            $schema->create('system_setting', static function (Blueprint $table): void {
                $table->comment('客户系统品牌、主题及默认界面');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->integer('id')->default(1)->check('id = 1')->comment('当前 Tenant 的设置槽位，固定为 1');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('official_url', 512)->comment('公开展示的官方网站地址');
                $table->string('description', 500)->comment('供管理人员阅读的说明');
                $table->string('logo_url', 512)->comment('品牌标识资源地址，空值采用缺省品牌');
                $table->string('timezone', 64)->comment('界面默认使用的 IANA 时区名称');
                $table->string('theme_mode', 8)->comment('默认主题模式：light、dark 或 auto');
                $table->string('theme_color', 16)->comment('默认主题主色，六位十六进制颜色');
                $table->string('theme_radius', 8)->comment('默认组件圆角配置');
                $table->string('layout_mode', 32)->comment('默认工作区布局模式');
                $table->integer('sidebar_collapsed')->default(0)->comment('侧栏默认折叠状态');
                $table->string('navigation_style', 16)->comment('默认导航外观');
                $table->integer('navigation_split')->default(1)->comment('混合导航是否拆分显示');
                $table->integer('breadcrumb_enable')->default(1)->comment('是否默认显示面包屑');
                $table->integer('breadcrumb_show_icon')->default(1)->comment('面包屑是否默认显示图标');
                $table->string('breadcrumb_style', 16)->comment('默认面包屑样式');
                $table->integer('tabbar_enable')->default(0)->comment('是否默认启用标签页');
                $table->string('tabbar_style', 16)->comment('默认标签页样式');
                $table->integer('footer_enable')->default(0)->comment('是否默认显示页脚');
                $table->integer('footer_fixed')->default(0)->comment('页脚是否默认固定');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['tenant_id', 'id']);
                $table->foreign('tenant_id', 'platform_tenant', 'id');
            });
        }
        if (!$schema->hasTable('platform_menu')) {
            $schema->create('platform_menu', static function (Blueprint $table): void {
                $table->comment('平台菜单与共享子系统权限目录');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('realm', 16)->check('realm = \'platform\'')->comment('菜单所属身份域');
                $table->string('parent_id', 32)->nullable()->comment('上级节点标识；根节点为空');
                $table->string('kind', 16)->comment('业务记录类型');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('path', 200)->comment('页面或目录的路由路径');
                $table->string('component', 100)->comment('构建期登记的页面组件标识');
                $table->string('permission', 120)->nullable()->unique()->comment('构建期登记的权限码；纯目录节点为空');
                $table->string('icon', 100)->comment('菜单图标标识');
                $table->integer('order_number')->comment('同级节点的显示顺序，数值较小者优先');
                $table->integer('enabled')->comment('当前是否启用');
                $table->integer('hidden')->comment('是否从导航中隐藏');
                $table->integer('guarded')->comment('受保护的系统菜单节点标志');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['order_number', 'id'], 'platform_menu_directory');
                $table->foreign('parent_id', 'platform_menu', 'id');
            });
        }
        if (!$schema->hasTable('platform_user')) {
            $schema->create('platform_user', static function (Blueprint $table): void {
                $table->comment('平台管理人员账号');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('login', 100)->comment('规范化的登录账号');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('password_hash', 255)->comment('登录口令散列，不进入公开投影');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->integer('version')->default(1)->comment('资料版本由身份服务在授权锁内维护，登录计数不属于资料变更');
                $table->integer('failures')->default(0)->comment('当前口令验证连续失败次数');
                $table->bigInteger('locked_until')->default(0)->comment('口令验证锁定截止时间，UTC Unix 秒；0 表示未锁定');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['login'], 'platform_user_login_unique');
            });
        }
        if (!$schema->hasTable('platform_session')) {
            $schema->create('platform_session', static function (Blueprint $table): void {
                $table->comment('平台登录会话摘要');
                $table->string('token_hash', 64)->comment('会话令牌的 SHA-256 摘要，不保存原始令牌');
                $table->string('id', 32)->unique()->comment('记录的稳定标识');
                $table->string('user_id', 32)->comment('对应人员账号的稳定标识');
                $table->bigInteger('expires_at')->comment('有效截止时间，UTC Unix 秒');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['token_hash']);
                $table->index(['user_id', 'created_at'], 'platform_session_user');
                $table->index(['expires_at'], 'platform_session_expiry');
                $table->foreign('user_id', 'platform_user', 'id');
            });
        }
        if (!$schema->hasTable('platform_role')) {
            $schema->create('platform_role', static function (Blueprint $table): void {
                $table->comment('平台角色及权限集合');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('scope_id', 32)->comment('平台作用域标识，固定为 platform');
                $table->string('name', 100)->comment('业务显示名称');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->integer('protected')->default(0)->comment('受保护超级管理员角色标志');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['scope_id', 'id'], 'platform_role_scope_id_id_unique');
                $table->unique(['scope_id', 'name'], 'platform_role_scope_id_name_unique');
            });
        }
        if (!$schema->hasTable('platform_user_role')) {
            $schema->create('platform_user_role', static function (Blueprint $table): void {
                $table->comment('平台账号与角色的授权关系');
                $table->string('user_id', 32)->comment('平台账号标识');
                $table->string('role_id', 32)->comment('平台角色标识');
                $table->primary(['user_id', 'role_id']);
                $table->foreign('user_id', 'platform_user', 'id');
                $table->foreign('role_id', 'platform_role', 'id');
            });
        }
        if (!$schema->hasTable('platform_role_permission')) {
            $schema->create('platform_role_permission', static function (Blueprint $table): void {
                $table->comment('平台角色与菜单权限码的授权关系');
                $table->string('role_id', 32)->comment('平台角色标识');
                $table->string('permission', 120)->comment('已授权的菜单权限码');
                $table->primary(['role_id', 'permission']);
                $table->foreign('role_id', 'platform_role', 'id');
                $table->foreign('permission', 'platform_menu', 'permission');
            });
        }
        if (!$schema->hasTable('platform_audit')) {
            $schema->create('platform_audit', static function (Blueprint $table): void {
                $table->comment('平台身份与业务操作的审计事实');
                $table->string('id', 32)->comment('审计事件的稳定标识');
                $table->string('tenant_id', 32)->nullable()->comment('事件所属 Tenant；平台自身事件为空');
                $table->string('actor_id', 32)->comment('真实操作者标识');
                $table->string('action', 80)->comment('已登记的审计动作');
                $table->string('subject_id', 100)->comment('被操作对象的稳定标识');
                $table->string('result', 20)->comment('结果：success、denied、failed 或 unknown');
                $table->text('details')->comment('不含秘密的有界附加事实');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->string('category', 16)->default('identity')->comment('审计类别，默认 identity');
                $table->string('operation_id', 32)->nullable()->comment('同一业务操作的关联标识');
                $table->string('event_key', 16)->nullable()->comment('同一操作内的事件键，用于拒绝重复事实');
                $table->string('request_id', 32)->nullable()->comment('来源请求标识');
                $table->string('stage', 16)->nullable()->comment('操作阶段');
                $table->primary(['id']);
                $table->index(['created_at', 'id'], 'platform_audit_time');
                $table->index(['tenant_id', 'category', 'created_at', 'id'], 'platform_audit_tenant_category_time');
                $table->unique(['operation_id', 'event_key'], 'platform_audit_operation_event');
            });
        }
        if (!$schema->hasTable('platform_mqtt_operation')) {
            $schema->create('platform_mqtt_operation', static function (Blueprint $table): void {
                $table->comment('平台 MQTT 管理操作的提交上下文与回执');
                $table->string('operation_id', 32)->comment('管理操作的稳定标识');
                $table->text('context_json')->comment('不含秘密的操作上下文');
                $table->string('context_hash', 64)->comment('操作上下文摘要');
                $table->string('stage', 20)->comment('操作阶段');
                $table->string('result', 20)->comment('操作结果');
                $table->bigInteger('version')->comment('操作并发版本');
                $table->text('receipts_json')->comment('节点回执清单');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['operation_id']);
            });
        }
        if (!$schema->hasTable('system_menu')) {
            $schema->create('system_menu', static function (Blueprint $table): void {
                $table->comment('客户系统菜单与权限目录');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('realm', 16)->check('realm = \'system\'')->comment('菜单所属身份域');
                $table->string('parent_id', 32)->nullable()->comment('上级节点标识；根节点为空');
                $table->string('kind', 16)->comment('业务记录类型');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('path', 200)->comment('页面或目录的路由路径');
                $table->string('component', 100)->comment('构建期登记的页面组件标识');
                $table->string('permission', 120)->nullable()->unique()->comment('构建期登记的权限码；纯目录节点为空');
                $table->string('icon', 100)->comment('菜单图标标识');
                $table->integer('order_number')->comment('同级节点的显示顺序，数值较小者优先');
                $table->integer('enabled')->comment('当前是否启用');
                $table->integer('hidden')->comment('是否从导航中隐藏');
                $table->integer('guarded')->comment('受保护的系统菜单节点标志');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->index(['order_number', 'id'], 'system_menu_directory');
                $table->foreign('parent_id', 'system_menu', 'id');
            });
        }
        if (!$schema->hasTable('system_user')) {
            $schema->create('system_user', static function (Blueprint $table): void {
                $table->comment('直接归属客户的系统账号');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('login', 100)->comment('规范化的登录账号');
                $table->string('name', 100)->comment('业务显示名称');
                $table->string('password_hash', 255)->comment('登录口令散列，不进入公开投影');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->integer('version')->default(1)->comment('资料版本由身份服务在授权锁内维护，登录计数不属于资料变更');
                $table->integer('failures')->default(0)->comment('当前口令验证连续失败次数');
                $table->bigInteger('locked_until')->default(0)->comment('口令验证锁定截止时间，UTC Unix 秒；0 表示未锁定');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'login'], 'system_user_tenant_id_login_unique');
                $table->unique(['tenant_id', 'id'], 'system_user_tenant_id_id_unique');
                $table->foreign('tenant_id', 'platform_tenant', 'id');
            });
        }
        if (!$schema->hasTable('system_session')) {
            $schema->create('system_session', static function (Blueprint $table): void {
                $table->comment('客户系统登录会话摘要');
                $table->string('token_hash', 64)->comment('会话令牌的 SHA-256 摘要，不保存原始令牌');
                $table->string('id', 32)->unique()->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('user_id', 32)->comment('对应人员账号的稳定标识');
                $table->bigInteger('expires_at')->comment('有效截止时间，UTC Unix 秒');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->string('actor_id', 32)->nullable()->comment('真实平台操作者的账号标识');
                $table->string('source_session_id', 32)->nullable()->comment('来源平台会话标识，用于撤销后续模拟访问');
                $table->primary(['token_hash']);
                $table->index(['user_id', 'created_at'], 'system_session_user');
                $table->index(['expires_at'], 'system_session_expiry');
                $table->foreign('actor_id', 'platform_user', 'id');
                $table->foreign('source_session_id', 'platform_session', 'id', 'CASCADE');
                $table->foreign(['tenant_id', 'user_id'], 'system_user', ['tenant_id', 'id']);
                $table->foreign('user_id', 'system_user', 'id');
            });
        }
        if (!$schema->hasTable('system_role')) {
            $schema->create('system_role', static function (Blueprint $table): void {
                $table->comment('客户系统角色及权限集合');
                $table->string('id', 32)->comment('记录的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('name', 100)->comment('业务显示名称');
                $table->integer('enabled')->default(1)->comment('当前是否启用');
                $table->integer('protected')->default(0)->comment('受保护超级管理员角色标志');
                $table->integer('version')->default(1)->comment('资料并发版本');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['id']);
                $table->unique(['tenant_id', 'id'], 'system_role_tenant_id_id_unique');
                $table->unique(['tenant_id', 'name'], 'system_role_tenant_id_name_unique');
            });
        }
        if (!$schema->hasTable('system_user_role')) {
            $schema->create('system_user_role', static function (Blueprint $table): void {
                $table->comment('客户系统账号与角色的授权关系，并保留 Tenant 范围');
                $table->string('user_id', 32)->comment('客户系统账号标识');
                $table->string('role_id', 32)->comment('客户系统角色标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->primary(['user_id', 'role_id']);
                $table->foreign('user_id', 'system_user', 'id');
                $table->foreign('role_id', 'system_role', 'id');
                $table->foreign(['tenant_id', 'user_id'], 'system_user', ['tenant_id', 'id']);
                $table->foreign(['tenant_id', 'role_id'], 'system_role', ['tenant_id', 'id']);
            });
        }
        if (!$schema->hasTable('system_role_permission')) {
            $schema->create('system_role_permission', static function (Blueprint $table): void {
                $table->comment('客户系统角色与菜单权限码的授权关系');
                $table->string('role_id', 32)->comment('客户系统角色标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('permission', 120)->comment('已授权的菜单权限码');
                $table->primary(['role_id', 'permission']);
                $table->foreign('role_id', 'system_role', 'id');
                $table->foreign('permission', 'system_menu', 'permission');
                $table->foreign(['tenant_id', 'role_id'], 'system_role', ['tenant_id', 'id']);
            });
        }
        if (!$schema->hasTable('system_audit')) {
            $schema->create('system_audit', static function (Blueprint $table): void {
                $table->comment('客户系统身份与业务操作的审计事实');
                $table->string('id', 32)->comment('审计事件的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->string('actor_id', 32)->comment('真实操作者标识');
                $table->string('action', 80)->comment('已登记的审计动作');
                $table->string('subject_id', 100)->comment('被操作对象的稳定标识');
                $table->string('result', 20)->comment('结果：success、denied、failed 或 unknown');
                $table->text('details')->comment('不含秘密的有界附加事实');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->string('category', 16)->default('identity')->comment('审计类别，默认 identity');
                $table->string('operation_id', 32)->nullable()->comment('同一业务操作的关联标识');
                $table->string('event_key', 16)->nullable()->comment('同一操作内的事件键，用于拒绝重复事实');
                $table->string('request_id', 32)->nullable()->comment('来源请求标识');
                $table->string('stage', 16)->nullable()->comment('操作阶段');
                $table->primary(['id']);
                $table->index(['created_at', 'id'], 'system_audit_time');
                $table->index(['tenant_id', 'category', 'created_at', 'id'], 'system_audit_tenant_category_time');
                $table->unique(['operation_id', 'event_key'], 'system_audit_operation_event');
            });
        }
        if (!$schema->hasTable('system_mqtt_operation')) {
            $schema->create('system_mqtt_operation', static function (Blueprint $table): void {
                $table->comment('客户系统 MQTT 管理操作的提交上下文与回执');
                $table->string('operation_id', 32)->comment('管理操作的稳定标识');
                $table->string('tenant_id', 32)->comment('所属 Tenant 标识，由可信执行上下文约束');
                $table->text('context_json')->comment('不含秘密的操作上下文');
                $table->string('context_hash', 64)->comment('操作上下文摘要');
                $table->string('stage', 20)->comment('操作阶段');
                $table->string('result', 20)->comment('操作结果');
                $table->bigInteger('version')->comment('操作并发版本');
                $table->text('receipts_json')->comment('节点回执清单');
                $table->integer('recovery_verified')->default(1)->comment('当前授权事实是否已完成恢复核对');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->bigInteger('updated_at')->comment('最后更新时间，UTC Unix 秒');
                $table->primary(['operation_id']);
            });
        }
        if (!$schema->hasTable('platform_handoff')) {
            $schema->create('platform_handoff', static function (Blueprint $table): void {
                $table->comment('平台模拟登录一次性交接码');
                $table->string('code_hash', 64)->comment('一次性交接码摘要，不保存原始交接码');
                $table->string('target_tenant_id', 32)->comment('交接目标客户；平台记录由来源平台会话管理');
                $table->string('user_id', 32)->comment('对应人员账号的稳定标识');
                $table->integer('user_version')->comment('签发时目标账号的资料版本');
                $table->integer('tenant_version')->comment('签发时目标客户的资料版本');
                $table->string('actor_id', 32)->comment('真实平台操作者的账号标识');
                $table->string('source_session_id', 32)->comment('来源平台会话标识，用于撤销后续模拟访问');
                $table->string('target_origin', 300)->comment('交接绑定的目标入口地址');
                $table->bigInteger('expires_at')->comment('有效截止时间，UTC Unix 秒');
                $table->bigInteger('consumed_at')->nullable()->comment('交接码消费时间，UTC Unix 秒；空值表示尚未消费');
                $table->bigInteger('created_at')->comment('创建时间，UTC Unix 秒');
                $table->primary(['code_hash']);
                $table->foreign('target_tenant_id', 'platform_tenant', 'id');
                $table->foreign(['target_tenant_id', 'user_id'], 'system_user', ['tenant_id', 'id'], 'CASCADE');
                $table->foreign('actor_id', 'platform_user', 'id');
                $table->foreign('source_session_id', 'platform_session', 'id', 'CASCADE');
            });
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists('platform_handoff');
        $schema->dropIfExists('system_mqtt_operation');
        $schema->dropIfExists('system_audit');
        $schema->dropIfExists('system_role_permission');
        $schema->dropIfExists('system_user_role');
        $schema->dropIfExists('system_role');
        $schema->dropIfExists('system_session');
        $schema->dropIfExists('system_user');
        $schema->dropIfExists('system_menu');
        $schema->dropIfExists('platform_mqtt_operation');
        $schema->dropIfExists('platform_audit');
        $schema->dropIfExists('platform_role_permission');
        $schema->dropIfExists('platform_user_role');
        $schema->dropIfExists('platform_role');
        $schema->dropIfExists('platform_session');
        $schema->dropIfExists('platform_user');
        $schema->dropIfExists('platform_menu');
        $schema->dropIfExists('system_setting');
        $schema->dropIfExists('platform_setting');
        $schema->dropIfExists('platform_tenant');
        $schema->dropIfExists('platform_installation');
    }
};
