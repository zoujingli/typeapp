# Windows HTTP 共同装配路径断言

[Windows 运行](https://github.com/zoujingli/typeapp/actions/runs/38837392358)固定源码 `c0ba1367ef703d73819ede22637e8fa906351ef0`，工具链、公开契约和三库 ORM 步骤已通过。随后 `tests/http-assembly.php --native` 编译完成，在检查生产源码归属时报告“独立装配仍依赖主仓源码”，尚未执行该程序的命令或 HTTP 行为。

## 复演与修正

测试根目录来自 `dirname(__DIR__)`，Windows 保留反斜线；拼接临时目录后形成混合分隔符。构建报告的源码通过 `BuildIdentity::sources()`、`BuildPlatform::resolve()` 统一为正斜线，测试却直接对两种表示执行 `str_starts_with()`。

使用原日志中的消费者目录和现有 `BuildPlatform::path()` 构造最小字节对照，原断言返回 false；仅统一目录表示后返回 true。这证明路径表示本身足以误报，不用于证明原报告中的每个文件均来自独立消费者。

测试根目录改为复用 `BuildPlatform::resolve()`。每个源码的独立目录前缀检查保持执行，未放宽到仅比较文件名，也不允许主仓源码或相邻目录混入。本机锁定 SDK 的 HTTP/CLI 共同装配 PHP 行为通过，包含八请求并发、子作用域、异常脱敏及资源归还；它不能替代 Windows 原生结果。

## 定向复验与证据

Windows 工作流增加 `scope=application`，从共同装配继续运行应用和三库模板，沿用完整入口的运行配置和原命令。这个范围跳过已执行的工具链、公开契约和独立 ORM，不作为完整发布证据。正式发布仍运行 `full`。

原工作流没有收录失败共同装配的可执行文件与构建报告；本次补齐装配和业务事件的原始程序、资源、失败信息及已有验收记录保存，便于后续直接核对实际源码。工作流 `actionlint`、修改文件的格式与语法，以及 56 项发布相关测试、145 个断言均通过。Windows 修复后的实际结果待定向运行完成后补充，RC17 尚未发布。
