import { createRequire } from 'node:module';

// TypeScript 7 的原生 API 不实现 Vue 工具当前需要的 JavaScript 插件接口。
// Vue 编译与类型检查共同使用官方维护的 API 桥接，主 tsc 仍固定为 7。
const require = createRequire(import.meta.url);
const bridge = createRequire(require.resolve('typescript-vue/package.json'));
export const vueTypeScriptPath = bridge.resolve('@typescript/old/lib/tsc');
export const loadVueTypeScript = () => bridge('@typescript/old');
