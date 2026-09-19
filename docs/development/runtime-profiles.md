# 真实embed运行依赖

探针和发布包共用`RuntimeIni`生成INI：禁用预加载、运行时动态扩展与外部源码包含，统一路径转义和控制字符/环境插值拒绝。探针使用空extension_dir及绝对模块路径，发布使用明确lib/bin及相对文件名；模块顺序由调用方提供，不被重新排序。共享生成器不读取宿主INI，也不替代`RuntimeProfile`的真实ABI/函数加载验证。默认 `swoole.enable_library=On`，官方内置 PHP 库在 Swoole 请求初始化中加载；该例外不允许业务或其他依赖解释回退。模块摘要约束内嵌库字节，INI 和实际公开函数要求一起参与构建身份。

`RuntimeProfile`属于`type-build`，只在构建端运行。它不执行应用PHP、不读取.env、不复制宿主INI；直接初始化选定SDK的embed，读取模块和函数表，并核对版本、ZTS及实际加载的核心库路径。CLI具有某个扩展不再被当作embed已有该能力的证据。

## 声明

Composer生产依赖的`ext-*`要求自动参与探测。应用额外运行能力使用构建JSON的`runtime`，按真实宿主平台分开：

```json
{
  "runtime": {
    "Linux": {
      "extensions": ["pcntl"],
      "functions": ["pcntl_signal", "pcntl_async_signals", "pcntl_signal_get_handler"],
      "modules": {
        "pcntl": {
          "file": "/已准备的SDK模块/pcntl.so",
          "sha256": "模块文件的准确64位小写十六进制SHA256"
        }
      }
    },
    "Darwin": {
      "extensions": ["pcntl"],
      "functions": ["pcntl_signal", "pcntl_async_signals", "pcntl_signal_get_handler"]
    }
  }
}
```

示例中的摘要需替换为实际受信模块摘要，不能直接作为可用配置。相对模块路径以项目根解释，绝对路径可指向单独管理的SDK。非当前平台不访问模块文件；已经内置的扩展也不会加载或读取备用文件。模块声明本身隐含要求该扩展存在。扩展与函数名均规范化为小写，列表有限且去重。

物联中心标准项目和模板显式声明Unix HTTP停止所需PCNTL函数；Windows不声明PCNTL，其控制事件仍由原生桥提供。PCNTL本身不是Windows能力。[PHP PCNTL安装边界](https://www.php.net/manual/en/pcntl.installation.php)

## 选择与失败语义

1. 使用空扫描目录和受控INI探测真正embed的内置能力。
2. 仅为缺失的要求选择共享模块：优先明确的文件/摘要候选，否则使用对应SDK扩展目录的标准文件名。
3. 用最终模块组合再次初始化同一embed。启动警告、模块格式/摘要错误、版本/ZTS不符或缺失函数立即拒绝，不等到HTTP服务启动才发现。
4. 收集所选模块及实际动态库依赖，把原始文件、生成的运行配置、探针源码/二进制与结果纳入构建身份。探测中或指纹收集中发生变化会拒绝。
5. 发布器根据封装清单复制模块和库、生成发布INI，并保留运行扩展/函数门禁。不能在封装后手工补模块或修改摘要来绕过身份。

共享候选的SHA256是调用方固定输入的依据，不独立证明来源真实。模块必须来自可信且匹配的SDK；构建不会自动下载扩展。未声明的扩展依赖、不是正常PHP扩展的库或不兼容ABI，都会通过实际加载失败或警告被拒绝。Zend扩展、WASI等不同加载模型没有由本机制自动获得支持。

探针在自己的C进程中设置PHPRC/扫描目录，避免让构建用PHP CLI误加载只属于embed的共享模块。它只查询运行时注册信息，不执行PHP脚本；这与把业务交给解释器运行是不同路径。[锁定PHP embed实现](https://github.com/php/php-src/blob/php-8.5.10/sapi/embed/php_embed.c)

## 产物与运行

构建目录的`runtime-profile/native.ini`用于原生产物的本机调试，引用构建端模块位置，不能当作可搬迁部署配置。构建报告包含该路径和实际扩展/函数要求。生产仍使用`type package`生成的发布目录；发布INI引用已经复制并校验的库，不依赖SDK或构建探针。

生成的`BuildIdentity::verifyRuntime()`检查扩展版本和明确要求的函数，并继续执行原有运行库字节/实际加载检查。新增运行依赖影响构建身份与缓存，不复用缺少这些依赖的旧产物。

## 已执行验收

`tests/runtime-profile.php`在Linux和macOS使用真实SDK验证：内置能力不重复加载、非当前平台/不需要的候选不读取、探针字节重复生成稳定、模块摘要、缺失函数、启动警告以及同平台非扩展库拒绝。Linux另验证共享PCNTL的版本与函数表；macOS保留其实际内置PCNTL路径。

Windows探针编译/模块加载仍待可用原生runner验证。原有隔离构建、所有平台最终同一源码快照CI和完整发布门禁仍须分别完成。
