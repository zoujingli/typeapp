<?php

declare(strict_types=1);

namespace app\catalog\service;

use app\catalog\model\Label;
use app\catalog\model\Product;
use app\catalog\event\ProductChanged;
use Type\Cache\Attribute\Cacheable;
use Type\Cache\Attribute\CacheEvict;
use Type\Cache\TypedCache;
use Type\Core\BusinessEvents;
use Type\Orm\Attribute\Transactional;
use Type\Orm\Db;
use Type\Orm\ModelException;
use Type\Orm\ModelQuery;
use Type\Orm\Outbox\Store;

/** 原 Service 类型负责目录业务；连接由当前执行作用域借用。 */
final class ProductService
{
    private int $loads = 0;
    /** @param array{name?:string} $filters 白名单搜索器输入。 */
    public function page(array $filters): array
    {
        $query = Product::query()->search($filters, [
            'name' => static fn (ModelQuery $query, mixed $value): ModelQuery => $query->where('name', '=', $value),
        ]);
        $result = [];
        foreach ($query->orderBy('id')->limit(20)->get() as $record) {
            if (!$record instanceof Product) {
                throw new ModelException('invalid_hydration', '商品映射无效');
            }
            $result[] = $record->present();
        }
        return $result;
    }

    /** @return array<string,mixed> 明确公开的商品投影。 */
    public function find(int $id): array
    {
        return $this->load($id)->present();
    }

    /** @param array{code:string,name:string,note?:?string} $values 已校验业务值。 */
    #[Transactional(database: 'catalog')]
    public function create(array $values): array
    {
        $record = new Product(['code' => $values['code'], 'name' => $values['name'], 'note' => $values['note'] ?? null]);
        $record->save();
        return $this->find($record->id);
    }

    /** @param array{name?:string,note?:?string} $values 缺失与 null 分别处理；code 创建后不可修改。 */
    #[Transactional(database: 'catalog')]
    public function update(int $id, array $values): array
    {
        $record = $this->load($id);
        foreach (['name', 'note'] as $field) {
            if (array_key_exists($field, $values)) {
                $record->set($field, $values[$field]);
            }
        }
        $record->save();
        return $this->find($record->id);
    }

    /** 唯一身份竞争只允许数据库证明的 tenant_id/code；不重跑外部业务。 */
    public function ensure(array $values): array
    {
        $record = Product::firstOrCreate(['code' => $values['code']], ['name' => $values['name'], 'note' => $values['note'] ?? null]);
        return $record->present();
    }

    /** 批量入口保留驱动真实行数，不触发逐实例事件。 */
    public function import(): array
    {
        $rows = [['code' => 'imported', 'name' => '批量商品', 'note' => null]];
        $driver = Db::connection('catalog')->driverName();
        $affected = $driver === 'mysql' ? Product::upsertAnyUnique($rows, ['name', 'note'])
            : Product::upsert($rows, ['tenant_id', 'code'], ['name', 'note']);
        return ['driver' => $driver, 'affected' => $affected];
    }

    /** 关系写入使本实例旧的已加载结果失效，再显式 loadMissing 获取新结果。 */
    #[Transactional(database: 'catalog')]
    public function label(int $id): array
    {
        $record = $this->load($id);
        Product::query()->with('labels')->load([$record]);
        $label = Label::firstOrCreate(['code' => 'featured']);
        $record->relation('labels')->attach($label->id);
        $invalidated = !$record->relationLoaded('labels');
        Product::query()->with('labels')->loadMissing([$record]);
        return ['invalidated' => $invalidated, 'product' => $record->project(['id', 'name'], ['labels' => ['id', 'code']])];
    }

    /** 缓存包含可信租户身份；null 是合法命中，回源异常不写入缓存。 */
    #[Cacheable(cache: 'cache', key: 'product:{tenant}:{id}', ttlMilliseconds: 60000, database: 'catalog')]
    public function cached(TypedCache $cache, string $tenant, int $id): ?array
    {
        if ($tenant !== \Type\Runtime\ExecutionScope::current()->binding('tenant_id')) {
            throw new \RuntimeException('catalog_cache_tenant_mismatch');
        }
        $this->loads++;
        $record = Product::query()->find($id);
        return $record instanceof Product ? $record->present() : null;
    }

    /** 显式失败演练证明异常不会作为值写入缓存。 */
    #[Cacheable(cache: 'cache', key: 'failed:{tenant}', ttlMilliseconds: 60000, database: 'catalog')]
    public function failing(TypedCache $cache, string $tenant): string
    {
        $this->loads++;
        throw new \RuntimeException('catalog_loader_failed');
    }

    /** 返回本服务真实进入回源方法的次数，用于核对命中与事务内绕过。 */
    public function loads(): int
    {
        return $this->loads;
    }

    /**
     * 原类型内调用 cached 仍受声明约束；只在同源最外层确认提交后失效。
     * 同步事件不发送外部副作用，afterCommit 不代替持久 Outbox。
     * @throws \RuntimeException 演练失败触发回滚，不自动重试未知提交。
     */
    #[Transactional(database: 'catalog')]
    #[CacheEvict(cache: 'cache', key: 'product:{tenant}:{id}', database: 'catalog')]
    public function change(TypedCache $cache, string $tenant, int $id, string $name, BusinessEvents $events, ProductChanged $sync, ProductChanged $after, Store $store, bool $fail = false): array
    {
        $product = $this->load($id);
        $product->name = $name;
        $product->save();
        $events->dispatch($sync);
        Db::afterCommit(static function () use ($events, $after): void {
            $events->dispatch($after);
        }, 'catalog');
        $store->enqueue('product:' . $id, 'catalog.deliver', 1, ['product_id' => $id], ['trace_id' => 'catalog-product-' . $id], 'catalog');
        $visible = $this->cached($cache, $tenant, $id);
        if ($fail) {
            throw new \RuntimeException('catalog_change_rolled_back');
        }
        return $visible ?? [];
    }

    /** @throws ModelException 当前租户中不存在该商品。 */
    private function load(int $id): Product
    {
        $record = Product::query()->find($id);
        if (!$record instanceof Product) {
            throw new ModelException('record_not_found', '商品不存在');
        }
        return $record;
    }
}
