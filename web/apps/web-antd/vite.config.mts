import { readFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { viteVxeTableImportsPlugin } from '../../scripts/vxe-table.ts';
import { viteTailwindReferencePlugin } from '../../scripts/tailwind-reference.ts';
import { registerTS } from 'vue/compiler-sfc';
import { loadVueTypeScript } from '../../scripts/typescript-bridge.mjs';

// Vue 的类型声明指向主 TypeScript 包；实际 JS 插件接口来自显式锁定的官方桥接。
registerTS(loadVueTypeScript as unknown as Parameters<typeof registerTS>[0]);
import tailwindcss from '@tailwindcss/vite';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'IOT_');
  const licenseMaterials = [
    ['LICENSE', new URL('../../LICENSE', import.meta.url)],
    ['NOTICE', new URL('../../../NOTICE', import.meta.url)],
    ['UPSTREAM.md', new URL('../../UPSTREAM.md', import.meta.url)],
    ['VBEN-LICENSE', new URL('../../VBEN-LICENSE', import.meta.url)],
  ] as const;
  return {
    plugins: [
      vue(),
      viteVxeTableImportsPlugin(),
      viteTailwindReferencePlugin(),
      tailwindcss(),
      {
        name: 'typeapp-license-materials',
        generateBundle() {
          for (const [fileName, source] of licenseMaterials) {
            this.emitFile({ type: 'asset', fileName, source: readFileSync(source, 'utf8') });
          }
        },
      },
    ],
    resolve: { alias: { '#': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: {
      port: 5173,
      strictPort: true,
      proxy: Object.fromEntries(['/public', '/platform', '/system', '/center', '^/t/[a-f0-9]{32}/(?:public|system|center)(?:/|$)'].map(path => [path, { target: env.IOT_API_ORIGIN || 'http://127.0.0.1:8080', changeOrigin: true }])),
    },
    build: { outDir: '../../dist', emptyOutDir: true },
  };
});
