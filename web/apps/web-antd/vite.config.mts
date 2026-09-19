import { readFileSync } from 'node:fs';
import { fileURLToPath, URL } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'IOT_');
  const licenseMaterials = [
    ['LICENSE', new URL('../../LICENSE', import.meta.url)],
    ['NOTICE', new URL('../../../NOTICE', import.meta.url)],
    ['UPSTREAM.md', new URL('../../UPSTREAM.md', import.meta.url)],
  ] as const;
  return {
    plugins: [
      vue(),
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
      proxy: Object.fromEntries(['/public', '/admin', '/customer', '/broker'].map(path => [path, { target: env.IOT_API_ORIGIN || 'http://127.0.0.1:8080', changeOrigin: true }])),
    },
    build: { outDir: '../../dist', emptyOutDir: true },
  };
});
