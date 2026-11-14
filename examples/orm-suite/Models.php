<?php

declare (strict_types=1);
namespace TypeApp\OrmSuite;

use Type\Orm\Model;
/**
 * 首版冻结 Schema 应用后的业务模型。
 */
final class CatalogRecord extends Model
{
    protected string $table = 'type_suite_catalog';
    protected array $schema = ['id' => 'int', 'code' => 'string', 'title' => 'string', 'active' => 'bool', 'age' => 'int', 'money' => ['type' => 'decimal', 'precision' => 30, 'scale' => 2], 'recorded_at' => 'datetime', 'notes' => '?string'];
}
/**
 * 演进后保持原数据，仅显式调整表及属性映射。
 */
final class CatalogEntry extends Model
{
    protected string $table = 'type_suite_catalog_entries';
    protected array $schema = ['id' => 'int', 'code' => 'string', 'heading' => 'string', 'status' => ['type' => 'string', 'column' => 'state'], 'active' => 'bool', 'age' => 'int', 'money' => ['type' => 'decimal', 'precision' => 30, 'scale' => 2], 'recorded_at' => 'datetime', 'notes' => '?string'];
}
/**
 * 模型声明非空不能替代数据库可空、部分或表达式索引的真实事实。
 */
final class UnsafeUniqueRecord extends Model
{
    protected string $table = 'type_suite_unsafe_unique';
    protected bool $autoIncrement = false;
    protected array $schema = ['id' => 'int', 'code' => 'string'];
}
/**
 * 所有唯一键均包含租户的集合冲突模型，可安全接受 MySQL 任意唯一键语义。
 */
final class UpsertRecord extends Model
{
    protected string $table = 'type_suite_upserts';
    protected bool $autoIncrement = false;
    protected ?string $versionKey = 'version';
    protected ?string $createTime = 'created_at';
    protected ?string $updateTime = 'updated_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'code' => 'string', 'title' => 'string', 'created_at' => 'int', 'updated_at' => 'int', 'version' => 'int'];
}
/**
 * 无生命周期的冲突模型保留数据库原始未变化影响行数。
 */
final class UpsertLabel extends Model
{
    protected string $table = 'type_suite_upsert_labels';
    protected bool $autoIncrement = false;
    protected array $schema = ['id' => 'int', 'code' => 'string', 'title' => 'string'];
}
/**
 * 获取或创建与冲突写入使用真实双唯一约束及受管生命周期。
 */
final class UniqueRecord extends Model
{
    protected string $table = 'type_suite_unique_records';
    protected bool $autoIncrement = false;
    protected ?string $versionKey = 'version';
    protected ?string $createTime = 'created_at';
    protected ?string $updateTime = 'updated_at';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'code' => 'string', 'alias' => 'string', 'optional_code' => '?string', 'created_at' => 'int', 'updated_at' => 'int', 'deleted_at' => '?datetime', 'version' => 'int'];
}
/**
 * 受管 Unix 秒时间与版本、租户和软删除共用真实写入路径。
 */
final class SecondRecord extends Model
{
    protected string $table = 'type_suite_second_records';
    protected bool $autoIncrement = false;
    protected ?string $versionKey = 'version';
    protected ?string $createTime = 'created_at';
    protected ?string $updateTime = 'updated_at';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'title' => 'string', 'value' => 'int', 'created_at' => 'int', 'updated_at' => 'int', 'deleted_at' => '?datetime', 'version' => 'int'];
}
/**
 * UTC 微秒时间必须由真实列精度与实例、集合写入共同保证。
 */
final class MicrosecondRecord extends Model
{
    protected string $table = 'type_suite_microsecond_records';
    protected bool $autoIncrement = false;
    protected ?string $versionKey = 'version';
    protected ?string $createTime = 'created_at';
    protected ?string $updateTime = 'updated_at';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'title' => 'string', 'value' => 'int', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => '?datetime', 'version' => 'int'];
}
/**
 * 不声明版本字段的集合算术同样需要完整回滚。
 */
final class CounterRecord extends Model
{
    protected string $table = 'type_suite_counters';
    protected bool $autoIncrement = false;
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'value' => 'int'];
}
/**
 * 集合写入回归使用显式主键，覆盖大集合的整批回滚。
 */
final class MutationRecord extends Model
{
    protected string $table = 'type_suite_mutations';
    protected bool $autoIncrement = false;
    protected ?string $versionKey = 'version';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'title' => 'string', 'value' => 'int', 'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false], 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
}
/**
 * 使用 archive 逻辑库的集合写入模型，验证同表不同连接身份不能混合关系加载。
 */
final class ArchiveMutationRecord extends Model
{
    protected string $table = 'type_suite_mutations';
    protected bool $autoIncrement = false;
    protected string $database = 'archive';
    protected ?string $versionKey = 'version';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'title' => 'string', 'value' => 'int', 'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false], 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
}
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class User extends Model
{
    protected string $table = 'type_suite_users';
    protected array $schema = ['id' => 'int', 'name' => ['type' => 'string', 'column' => 'display_name'], 'active' => 'bool', 'external_id' => ['type' => 'bigint', 'precision' => 30], 'credit' => ['type' => 'decimal', 'precision' => 30, 'scale' => 2], 'profile' => 'array', 'joined_at' => 'datetime', 'secret' => ['type' => 'string', 'visible' => false]];
    public function articles(): array
    {
        return $this->hasMany(Article::class, 'user_id');
    }
    public function details(): array
    {
        return $this->hasOne(Details::class, 'user_id');
    }
    public function records(): array
    {
        return $this->belongsToMany(ScopedRecord::class, 'type_suite_scoped_links', 'user_id', 'record_id', pivotTenant: 'tenant_id');
    }
    /**
     * 业务方法在开发与 AOT 转换后保留。
     */
    public function displayLabel(): string
    {
        return '用户：' . $this->name;
    }
}
namespace TypeApp\OrmSuite;

use Type\Orm\Model;
/**
 * 消费者自己的租户实体；不依赖应用账号或权限。
 */
final class ScopedRecord extends Model
{
    protected string $table = 'type_suite_scoped_records';
    protected ?string $versionKey = 'version';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'title' => 'string', 'value' => 'int', 'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false], 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
}
/**
 * 通过 workspace 属性映射 owner_ref，验证租户字段采用声明映射而非固定列名。
 */
final class ScopedLabel extends Model
{
    protected string $table = 'type_suite_scoped_labels';
    protected ?string $tenantKey = 'workspace';
    protected array $schema = ['id' => 'int', 'workspace' => ['type' => 'string', 'column' => 'owner_ref'], 'scope_id' => 'string', 'label' => 'string'];
}
namespace TypeApp\OrmSuite;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Article extends Model
{
    protected string $table = 'type_suite_articles';
    protected ?string $versionKey = 'version';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'user_id' => 'int', 'title' => 'string', 'status' => 'string', 'views' => 'int', 'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false], 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
    public function author(): array
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    public function tags(): array
    {
        return $this->belongsToMany(Tag::class, 'type_suite_article_tags', 'article_id', 'tag_id', pivotFields: ['weight']);
    }
}
namespace TypeApp\OrmSuite;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Tag extends Model
{
    protected string $table = 'type_suite_tags';
    protected array $schema = ['id' => 'int', 'label' => 'string'];
    public function articles(): array
    {
        return $this->belongsToMany(Article::class, 'type_suite_article_tags', 'tag_id', 'article_id', pivotFields: ['weight']);
    }
}
namespace TypeApp\OrmSuite;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Details extends Model
{
    protected string $table = 'type_suite_details';
    protected array $schema = ['id' => 'int', 'user_id' => 'int', 'bio' => 'string'];
    public function user(): array
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}