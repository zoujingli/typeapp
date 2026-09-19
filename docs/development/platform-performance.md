# 平台性能验证

性能比较使用明确指定的两个源码状态，在相同平台、SDK、数据库、负载和采样方法下运行；每个状态使用自身锁文件安装与全量编译。没有可比基准时报告未验证，不宣称性能改善。

## 准备与运行

准备可信 Composer、匹配的 PHP ZTS/embed SDK、原生数据库工具和 Swoole。源码参数必须是本地仓库可读取的完整 Git 提交；输出目录为本次独立创建的工作目录。

```sh
php tests/prepare-platform-benchmarks.php "$TYPE_BASE_SOURCE" "$TYPE_NEW_SOURCE" "$TYPE_COMPOSER_PHAR"
php tests/benchmark-pairs.php "$TYPE_PAIR_ROOT/old" "$TYPE_PAIR_ROOT/new" "$TYPE_MYSQL_TOOLS" "$TYPE_PGSQL_TOOLS"
php tests/benchmark-compare.php "$TYPE_MEASUREMENT/verification.json"
```

准备器从每个输入状态导出源码和锁文件，独立构建 PHPX、核对受控 CMake 适配与产物摘要。CI 的性能入口要求显式提供 `TYPE_BASE_SOURCE`；基准提交不内置在脚本中。

## 方法与边界

比较器核对报告完整性、内容摘要、平台、预热次数、样本和并发。测量记录吞吐、p50/p95/p99、CPU/RSS，CRUD 按逻辑操作计数，RSS 为受测进程及后代的采样总和。区间重叠不能证明性能等价，持续退化信号需要独立复验和定位。

当前成对比较工具仍包含待移除的通信引擎组合，不能作为 Swoole 唯一底层已完成的证明。完成通信迁移后须同步比较矩阵与平台入口；当前完整验收要求见[实现规划](../guide/roadmap.md)。
