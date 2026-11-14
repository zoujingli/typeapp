<?php

declare (strict_types=1);
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class User extends Model
{
    protected string $table = 'type_model_users';
    protected string $pk = 'id';
    protected array $schema = ['id' => 'int', 'name' => ['type' => 'string', 'column' => 'display_name'], 'age' => 'int', 'active' => 'bool', 'secret' => ['type' => 'string', 'visible' => false], 'note' => ['type' => '?string', 'required' => false]];
    public function labels(): array
    {
        return $this->belongsToMany(ScopedLabel::class, 'type_model_scoped_links', 'user_id', 'label_id', pivotTenant: 'tenant_id');
    }
    public function unscopedLabels(): array
    {
        return $this->belongsToMany(ScopedLabel::class, 'type_model_scoped_links', 'user_id', 'label_id');
    }
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Article extends Model
{
    protected string $table = 'type_model_articles';
    protected array $schema = ['id' => 'int', 'user_id' => '?int', 'title' => 'string'];
    public function tags(): array
    {
        return $this->belongsToMany(Tag::class, 'type_model_article_tag', 'article_id', 'tag_id', pivotFields: ['position']);
    }
    public function owner(): array
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Profile extends Model
{
    protected string $table = 'type_model_profiles';
    protected array $schema = ['id' => 'int', 'user_id' => 'int', 'bio' => 'string'];
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Tag extends Model
{
    protected string $table = 'type_model_tags';
    protected array $schema = ['id' => 'int', 'label' => 'string', 'internal' => ['type' => 'string', 'visible' => false, 'required' => false]];
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Invoice extends Model
{
    protected string $table = 'type_model_invoices';
    protected array $schema = ['id' => 'int', 'external_id' => ['type' => 'bigint', 'precision' => 30], 'amount' => ['type' => 'decimal', 'precision' => 30, 'scale' => 2], 'happened_at' => 'datetime', 'note' => ['type' => '?string', 'required' => false], 'internal' => ['type' => 'string', 'visible' => false, 'required' => false]];
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Document extends Model
{
    protected string $table = 'type_model_documents';
    protected ?string $versionKey = 'version';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'title' => 'string', 'status' => 'string', 'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false], 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 类型属性模型，构建器保留类名并生成状态访问钩子。
 */
final class Counter extends Model
{
    protected string $table = 'type_model_counters';
    protected ?string $versionKey = 'version';
    protected array $schema = ['id' => 'int', 'value' => 'int', 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 按映射列 tenant_id 自动隔离，覆盖软删除、版本和部分字段读取。
 */
final class ScopedRecord extends Model
{
    protected string $table = 'type_model_scoped_records';
    protected ?string $versionKey = 'version';
    protected ?string $deleteTime = 'deleted_at';
    protected array $schema = ['id' => 'int', 'tenant_id' => 'string', 'user_id' => 'int', 'title' => 'string', 'value' => 'int', 'deleted_at' => ['type' => '?datetime', 'fillable' => false, 'required' => false], 'version' => ['type' => 'int', 'fillable' => false, 'required' => false]];
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 同名逻辑库的两个独立端点，用数据差异观察模型的实际选路。
 */
final class ReadWriteProbe extends Model
{
    protected string $table = 'type_rw_probe';
    protected bool $autoIncrement = false;
    protected array $schema = ['id' => ['type' => 'int', 'fillable' => true], 'value' => 'string', 'parent_id' => 'int'];
    public function children(): array
    {
        return $this->hasMany(ReadWriteProbe::class, 'parent_id');
    }
}
namespace TypeApp\ModelExample;

use Type\Orm\Model;
/**
 * 非默认租户列通过 Table 声明属性名；普通 scope_id 不自动变成租户。
 */
final class ScopedLabel extends Model
{
    protected string $table = 'type_model_scoped_labels';
    protected ?string $tenantKey = 'workspace';
    protected array $schema = ['id' => 'int', 'workspace' => ['type' => 'string', 'column' => 'owner_ref'], 'scope_id' => 'string', 'label' => 'string'];
}