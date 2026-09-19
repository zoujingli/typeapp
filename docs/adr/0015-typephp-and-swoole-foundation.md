# TypePHP 与 Swoole 统一底层

TypePHP 负责生产代码全量 AOT，Swoole 负责通信、进程、线程、协程和事件循环；Plugins 组合应用能力。TypeApp 只衔接编译入口、PHP ZTS/PHPX 生命周期、作用域、资源预算与业务语义，必要原生适配须具有固定版本的实证缺口和撤除条件。该分工减少重复运行时实现，具体规则见[Swoole 复用标准](../standards/swoole-reuse.md)。
