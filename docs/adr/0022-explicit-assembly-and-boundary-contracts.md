# 显式装配与边界契约

TypeApp 采用标准共用、协议独立和显式应用装配：优先复用 PSR、Swoole、`ExecutionScope` 与 `ManagedResource`，应用入口直接构造所需组件；通信、存储、日志和队列等真实可替换边界可以使用接口，组件内部不为每个类增加抽象。TypePHP 在构建期生成必要的声明调用，生产运行不引入通用 DI 容器、运行时扫描、AOP 代理或统一 Transport/Server 管理器；HTTP、WebSocket、TCP、UDP、MQTT 保持各自协议入口，HTTP 与 WebSocket 共用端口时由一个 Swoole Server 拥有监听。这样保持契约和生命周期边界清晰，同时避免第二套对象管理机制破坏全量 AOT、Swoole-only 和单程序交付边界。
