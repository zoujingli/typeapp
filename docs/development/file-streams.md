# 上传下载与文件所有权

`SwooleServer` 使用 `RequestLimits` 限制请求字节、JSON 字段与数组元素、JSON 深度、表单字段、文件数量和单文件大小。Swoole 在接收时拒绝过大的 Content-Length，`upload_max_filesize=0` 关闭会绕过普通包长上限的大 multipart 特殊通道；不能把这个设置误解成禁止普通大小文件上传。

正文、urlencoded 和 multipart 可通过标准 PSR 请求读取。表单沿用查询参数的明确规则：标量和 `name[]` 列表，拒绝重复标量、标量/列表混合及嵌套方括号，避免 PHP 静默改名或覆盖。multipart 的 name 必须带引号；不接受嵌套 multipart、重复头或 Content-Transfer-Encoding。文件名及媒体类型仅保留为不可信客户端元数据。

`UploadStorage` 使用应用专属本地目录。`receive($stream, $scope, $maximumFileBytes)` 在每个 16 KiB 读取中核对单文件、总字节和文件数配额。共享文件锁只保护短暂磁盘操作，锁忙立即返回 503；所有实例必须使用同一个目录和配额。写满、同步失败和配额不足返回 507。目录只能由受信任应用进程写入，不承诺对敌对本地账户的路径竞争保护。

`PendingUpload::save()` 返回随机 32 位十六进制存储键，先同步文件、再同目录原子改名。未显式保存的临时文件随作用域清理；异常读取立即清理，清理不等待配额锁。已保存文件由应用拥有，通过 `open/remove` 使用；存储键不代替用户授权，数据库记录与文件保存不构成跨系统原子事务。目录项在主机突然断电后的持久性及进程 SIGKILL 后的孤立文件收集需要部署策略。

响应发送器只保留一个 16 KiB 块，不整流缓冲。HTTP 正文按 chunked 发送，HEAD 只返回可确定的长度。每次读取前后由作用域与流接口约束，第一块发送后发生异常只关闭连接，不再发送另一份错误 JSON，也不写成功终止块。流关闭发生在作用域关闭之前。

客户端停止读取时，发送等待继续使用请求的剩余截止预算。stream 在非阻塞写入无法推进时等待可写；Swoole 的发送等待到期后唤醒原发送协程，由原请求执行者关闭流和回收资源。计时器本身不跨协程关闭请求资源，也不重新给予一份完整请求预算。

## Swoole 接入层的临时目录

Swoole 6.2.2 的 multipart 路径可以创建自身临时文件；`http_parse_post=false` 不是普遍的禁止写盘开关，`http_parse_files=false` 也不能作为磁盘配额保证。框架保留有界原始正文并自行解析，不能宣称应用存储配额覆盖了接入层暂存。

生产处理 multipart 时，将 `RequestLimits::temporaryDirectory` 设置到专用且有硬容量限制的文件系统，例如容器内独立 tmpfs，`temporaryBytes` 声明其上限。构造时用实际文件系统容量核对声明，不接受把普通大磁盘目录冒充小容量卷；同卷已有文件会减少可用容量。默认未配置该目录时沿用 Swoole 临时目录，只适合已在外部配置磁盘配额的环境。入口并发及接收连接总量由负载控制任务统一设置，磁盘空间与内存容量仍分别计量。

## 已有验证

`composer test:files` 验证真实 PHP HTTP 上传、恶意存储键、multipart 二进制、所有输入限额、保存和异常回收、8 MiB 下载、HEAD、发送后异常、客户端上传和下载中断。`tests/upload-storage.php` 验证数量配额、锁竞争时的清理、截止预算和重复保存。

停止读取回归使用本轮 64 MiB 文件：客户端收到响应前缀后停止读取，要求服务在期限内关闭流，并确认没有读完或发送完整文件。该大小用于实际触发 socket/Swoole 发送背压；不能用能一次进入发送缓冲区的小文件证明等待有界。PHP 与 AOT 分别在 stream/Swoole 上执行同一用例。

实际 Linux 小容量 tmpfs 写满测试通过：运行该脚本时把 `TYPE_UPLOAD_FAULT_DIRECTORY` 指向独立 64 KiB tmpfs。HTTP 的独立 1 MiB 暂存卷验证用 `TYPE_HTTP_UPLOAD_TEMP` 指定，检查 Swoole 临时文件也在请求后消失。不要把测试写满目录指向用户已有存储。

原生入口为 `docs/build-config/type-file-http.json`，命令 `composer build:files` 和 `composer test:files-native`；按当前开发顺序集中验收。

## 真实满盘与并发的原生门禁

`composer test:files-pressure` 使用两个独立 1 MiB tmpfs（接入暂存与应用存储）及一个 64 KiB 存储回归卷，所有目录在本轮新建，不能指定用户已有数据目录。Linux CI 使用 `TYPE_TEST_EXECUTION=host` 受控挂载，本地使用测试容器的独立 tmpfs。

`TYPE_TEST_EXECUTION=host` 也支持 macOS 原生执行：以 `hdiutil` 创建三个独立 1 MiB FAT 映像，验证真实 ENOSPC、507 和恢复；空卷只允许系统创建的 `.fseventsd` 元数据。结束时只卸载本轮挂载，保留映像及日志。Linux 原生模式继续使用上述 tmpfs 配额，两种文件系统分别保留实际容量和占用证据。

受限文件系统的实际总容量、空卷前置条件与失败清理均由测试检查；超大 Content-Length、各项结构与文件限额继续由原来的 `test:files-native` 验证。最终日志为 `build/upload-pressure-final.log`，门禁加入原生 HTTP 分组，不再只依靠 PHP 存储测试推断 AOT 满盘行为。
