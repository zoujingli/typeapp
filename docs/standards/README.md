# 工程规范

TypeApp 以可运行的应用展示组件组合方式。规范既约束手写业务和组件代码，也约束生成代码、说明文档与验证证据。

- [项目标准](project.md)：统一命名、标准极简定位、100% 全量编译交付门槛与性能证据要求。
- [代码、PHPDoc 与 TypePHP](coding.md)：语法、格式规则及声明契约。
- [应用与组件结构](structure.md)：单应用、多应用、组件职责及配置位置。
- [物联网中心 Web 页面](iot-web.md)：Vben Admin Pro 选型及参考 SmartAdmin 的统一页面与交互要求，仅适用于物联网 Web 管理端。
- [测试与质量检查](testing.md)：快速测试、外部依赖、原生验证和提交要求。
- [锁定 TypePHP 0.9 语法基线](../standards/typephp.md)：来自当前固定版本官方文档、源码和测试的依据。

真实命令和依赖版本以根 `composer.json`、`composer.lock` 和 `toolchain.lock.json` 为准。运行输出存入任务专用目录，功能说明维护当前行为、验证方法和未完成边界，规范文件不把“有命令”当作“已通过”。
