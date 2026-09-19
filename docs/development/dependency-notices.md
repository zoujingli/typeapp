## 默认行为

应用和生产包保留实际`composer.json`的license声明；缺失文件时才使用提供的安装元数据。字符串与数组原样保留，不把多项声明重写为一种许可。[Composer许可字段](https://getcomposer.org/doc/04-schema.md#license)

仅在构建时收集包根的LICENSE/LICENCE、COPYING、NOTICE、COPYRIGHT文件及LICENSES目录；文件名只用于找原文，不用于猜许可类型。源码/私钥、符号链接、非法UTF-8、过大材料和错误摘要会被拒绝。重复原文按SHA256去重，原始字节与换行不改写。

生成资源：

```text
notices/dependencies.json           组件、版本、原声明、材料摘要、缺失项
notices/README.txt                  范围与限制
notices/texts/<sha256>.txt           原始材料字节
```

它们与应用其他资源共用内容分代、构建身份、复制校验和原生启动校验。新增、删除或修改材料都会改变索引，恢复缓存/发布产物前再次核对材料集合。发布根的NOTICES.md指向实际资源索引；归档保留这些文件。

## 显式原生库材料

原生库不能仅凭文件名推断组件、版本或许可。对当前平台的实际运行库，构建JSON可以提供准确绑定：

```json
{
  "notices": {
    "require-complete": false,
    "native": {
      "Darwin": {
        "libphpx.dylib": {
          "binary-sha256": "该运行库的准确SHA256",
          "component": "swoole/phpx",
          "version": "2.7.0",
          "license": "Apache-2.0",
          "files": [
            {"file": "/可信来源/LICENSE", "sha256": "该原文的准确SHA256"}
          ]
        }
      }
    }
  }
}
```

摘要占位符必须替换成真实64位小写十六进制值。只接受本次运行库清单中存在的名称；SDK库字节或原文摘要不匹配会拒绝。其他平台不访问这些本地文件。系统提供且未复制的Windows库单独标明，不伪装成已收集的第三方材料。

包内附加材料用`notices.packages`，键必须是当前应用或真实生产包名，值为`[{"file":"包内相对路径","sha256":"准确摘要"}]`。它不允许读取包外任意文件。构建工具本身等纯开发依赖不会因安装在vendor中而被当成生产依赖；PHPX等真实运行库由原生清单及显式来源单独记录。

## 缺失状态与严格门禁

显式设置`require-complete=true`后，任何这些缺口都在业务全量AOT前失败。`complete`只表示本清单中的声明和原文满足技术检查；始终保留`legal-review=not-assessed`、`distribution-authorization=not-assessed`。它不证明静态内嵌子依赖已全部发现、来源真实、许可兼容或源码提供等义务已满足，也不新增公开/私有分发权限。

当前 TypeApp 第一方内容声明 `Apache-2.0`，但材料收集仍保留每个依赖的原始许可证，不由工具自动生成或替换授权条款。没有原始许可文本的自有组件保留缺口；原生 SDK 的材料同样需要来自与实际二进制匹配的来源，不能借其他版本的 LICENSE 冒充完成。`swoole/typephp` 的 `GPL-3.0-only` 构建依赖继续单独记录，不被项目许可证覆盖。

## 实际验收

`tests/dependency-notices.php`覆盖原声明、原文字节、缺失报告、严格门禁、二进制/文本绑定、未知对象、材料新增/修改和源码/秘密拒绝。`tests/notices-build.php`在macOS及Linux完整编译标准应用，检查Composer原文及PHPX绑定材料进入发布、材料篡改在静态/原生启动两层拒绝、伪造完整状态被拒绝、恢复后启动及归档内容。

已有应用/Composer资源的原生读取与篡改回归、macOS原生缓存复用继续保留。当前覆盖不等于Windows、全部SDK材料或最终同提交交付已经完成。
