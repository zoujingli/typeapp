# 文档站第三方资源

这些发行文件随文档站保存，浏览器不需要从 CDN 下载脚本、主题或语言资源。文件来自固定版本的 npm 包，保持原始字节及许可原文；此处许可只覆盖相应第三方资源。

| 包 | 版本 | 来源 | 许可 |
| --- | --- | --- | --- |
| Docsify | 5.0.0 | [npm 发行包](https://registry.npmjs.org/docsify/-/docsify-5.0.0.tgz) | [MIT 原文](docsify/LICENSE) |
| PrismJS | 1.30.0 | [npm 发行包](https://registry.npmjs.org/prismjs/-/prismjs-1.30.0.tgz) | [MIT 原文](prism/LICENSE) |
| Mermaid | 11.17.2 | [npm 发行包](https://registry.npmjs.org/mermaid/-/mermaid-11.17.2.tgz) | [MIT 原文](mermaid/LICENSE) |

Docsify 取 `dist/docsify.min.js`、`dist/themes/core.min.css` 和 `dist/plugins/search.min.js`；PrismJS 只取 Bash、PHP、JSON 三个语言组件，核心与 PHP 所需的 markup-templating 已由 Docsify 包含。Mermaid 只取自包含的 `dist/mermaid.min.js`，用于渲染指南中的架构图与时序图。

## npm 包完整性

- Docsify：`sha512-F2LBvsG/RkW9VZs91FPceZpJBilSC9EYCid0n1YhnQb3OOw63dk70d1ITPR2RPwxmPPcWFQ+TOtpdImilLWyGw==`
- PrismJS：`sha512-DEvV2ZF2r2/63V+tK8hQvrR2ZGn10srHbXviTlcv7Kpzw8jWiNTqbVgjO3IY8RxrrOUF8VPMQQFysYYYv0YZxw==`
- Mermaid：`sha512-V6K3C8EBdEsPFZXSKMJe6ppQOENxuHARr9GvHX4hh47lAbhMRD9qf4oEK7LoaRQxULMa80/qt5gHO73aCleBBg==`

## 入仓文件 SHA-256

路径相对此目录。

| 文件 | SHA-256 |
| --- | --- |
| `docsify/docsify.min.js` | `dd215e90bfb7ba05d88e65e8b050016ff58b84f4426a336e86510e3ef95b1da9` |
| `docsify/core.min.css` | `d14c45ade932b1120be91d17d1ddce13d73b7266b9cddec538bb3ab19d1ace8b` |
| `docsify/search.min.js` | `5968d5576b85cf2d6bbab92ca5a5ed1461493e25a2b9d5755a2872edf8344591` |
| `docsify/LICENSE` | `65a671d4b5bda281aac624bc6901d8a8c494c15dc3e329760e8652aa0d76519a` |
| `prism/prism-bash.min.js` | `6260814110e5182f2956e3bd257429548d9dbf2a9b66a63719b26cf9fac966a7` |
| `prism/prism-php.min.js` | `9ec5c5a78996db36f16d000b81e3f1cd27ac4edb3e1fc0ff6fe5aba9f9c7b377` |
| `prism/prism-json.min.js` | `956d86baa5ae7ec4106758f354ac2d140bdcd7fc103dece02f73ed12b8d663e4` |
| `prism/LICENSE` | `2b947f0901a7ffcf08a89957da9783c0e9c6e72cb6ce8e959f501ab5409e4d2b` |
| `mermaid/mermaid.min.js` | `581ed7d74bd9048d0e3a91363927d72ef22942d7722546b27f7cc29e35390eb8` |
| `mermaid/LICENSE` | `ec9fb67dcb25eccc416ed56e1aab819222c805a2a4bfe4cb19e7556bf2ffde80` |
