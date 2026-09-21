# 缓存与事务的一致性边界

`CacheReader` 组合 TypedCache 与显式回源函数，strong=true 直接调用主库回源，既不读缓存也不回填缓存。普通读取仍采用有限 TTL 的最终一致语义。

应用示例 Inventory 在数据库事务中更新，afterCommit 删除对应缓存；回滚不执行删除。真实交错验证先读旧值、提交新值并失效、旧读取最后回填：普通读取在 TTL 内可看到旧值，强一致仍读取主库新值，旧缓存到期后重新回源。这里不声称单删或延迟双删提供强一致。

缓存故障默认传播；可显式配置 fallbackOnRedisFailure，在缓存读失败时回源，在回源成功而缓存写失败时返回已经取得的结果。回源函数自身失败不会被当作缓存故障重复执行。强一致入口在缓存不可用时仍直接走主库；此前数据库提交 UNKNOWN 仍由 ReadWriteSession 的对账规则约束。

PHP 验证默认使用真实 SQLite 文件数据库与 Redis，覆盖实际写入和旧值回填交错、回滚、TTL、真实 Redis 客户端断连、默认错误与显式回源；也可选择 MySQL 或 PostgreSQL。SQLite 不支持本框架的读副本配置；缓存旧值不等于数据库复制延迟，主从端点另由[模型连接与主从路由](model-connections.md)验收。两包保持独立，ORM/cache 组合仅在应用示例完成。原生入口为 `docs/build-config/type-cache-consistency.json`。
