import { chromium, expect } from '../web/node_modules/@playwright/test/index.mjs';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs';
import { createServer } from 'node:http';
import { dirname, extname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

// 复用真实导出器和仓库固定发布来源；修改只发生在本轮独立文档副本。
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const work = mkdtempSync(join(root, 'build/docs-browser-'));
const source = join(work, 'source with spaces');
mkdirSync(source);
cpSync(join(root, 'docs'), join(source, 'docs'), { recursive: true });
for (const name of ['LICENSE', 'NOTICE']) cpSync(join(root, name), join(source, name));
const report = { status: 'running', checks: [], errors: [], snapshots: [], cleanup: {} };
const exportSite = () => execFileSync('bash', [join(source, 'docs/build-site.sh'), '--repository', root], { cwd: work, encoding: 'utf8', timeout: 30000 }).trim();
const metadata = directory => JSON.parse(readFileSync(join(directory, 'channel.js'), 'utf8').replace(/^window\.TYPEAPP_DOCS = /, '').replace(/;\s*$/, ''));
let browser;
let context;
let server;
let activeOutput;
try {
  writeFileSync(join(source, 'docs/guide/channel-fixture.md'), '# 仅开发通道检索样本\n\n通道切换与搜索的独立验收页面。\n');
  writeFileSync(join(source, 'docs/_sidebar.md'), readFileSync(join(source, 'docs/_sidebar.md'), 'utf8') + '\n- [仅开发通道检索样本](/guide/channel-fixture.md)\n');
  const original = exportSite();
  writeFileSync(join(source, 'docs/guide/channel-fixture.md'), '# 更新后检索样本\n\n已替换上一代页面，旧索引不得继续命中。\n');
  const updated = exportSite();
  const originalRelease = metadata(original);
  const originalNext = metadata(join(original, 'next'));
  const updatedNext = metadata(join(updated, 'next'));
  expect(originalNext.contentIdentity).not.toBe(updatedNext.contentIdentity);
  expect(originalRelease.contentIdentity).toBe(metadata(updated).contentIdentity);
  activeOutput = original;
  server = createServer((request, response) => {
    try {
      const url = new URL(request.url, 'http://127.0.0.1');
      if (!url.pathname.startsWith('/preview/')) { response.writeHead(404).end(); return; }
      let relative = decodeURIComponent(url.pathname.slice('/preview/'.length));
      if (!relative || relative.endsWith('/')) relative += 'index.html';
      const file = realpathSync(resolve(activeOutput, relative));
      if (!file.startsWith(activeOutput + sep)) { response.writeHead(404).end(); return; }
      const contentType = { '.html': 'text/html', '.md': 'text/markdown', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.woff2': 'font/woff2', '.json': 'application/json' }[extname(file)] || 'application/octet-stream';
      response.writeHead(200, { 'Content-Type': contentType, 'Cache-Control': 'no-cache' }).end(readFileSync(file));
    } catch { response.writeHead(404).end(); }
  });
  await new Promise((accept, reject) => { server.once('error', reject); server.listen(0, '127.0.0.1', accept); });
  const origin = `http://127.0.0.1:${server.address().port}`;
  const base = `${origin}/preview/`;
  browser = await chromium.launch({ channel: 'msedge', headless: true, timeout: 30000 });
  report.browser = browser.version();
  context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
  context.setDefaultTimeout(15000);
  // 离线验证站点依赖；备案和外部文档链接只检查地址，不向第三方发送访问。
  await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
  const page = await context.newPage();
  page.on('pageerror', error => report.errors.push(error.message));
  const ready = async () => {
    await expect(page.locator('.markdown-section h1').first()).toBeVisible();
    await expect(page.locator('.docs-channel')).toBeVisible();
    await expect(page).toHaveTitle(/物联开源分享$/);
  };
  await page.goto(base);
  await ready();
  await expect(page.locator('.docs-channel-label')).toHaveText('发布版');
  await expect(page.locator('.docs-channel-version')).toHaveText(originalRelease.version);
  expect(await page.evaluate(() => window.TYPEAPP_DOCS.productTag)).toBe(originalRelease.productTag);
  report.snapshots.push({ release: originalRelease, next: originalNext });
  report.checks.push('发布版默认入口、准确产品身份与备案标题');

  await page.goto(`${base}#/guide/architecture`);
  await ready();
  await expect(page.locator('.diagram svg').first()).toBeVisible();
  const anchor = page.locator('.markdown-section h2[id]').first();
  const anchorId = await anchor.getAttribute('id');
  await page.goto(`${base}#/guide/architecture?id=${encodeURIComponent(anchorId)}`);
  await ready();
  await page.getByRole('link', { name: '查看开发版 →' }).click();
  await ready();
  await expect(page).toHaveURL(new RegExp('/preview/next/#/guide/architecture\\?id='));
  await expect(page.locator('.docs-channel-label')).toHaveText('开发版');
  expect(await page.evaluate(() => window.TYPEAPP_DOCS.productTag)).toBeNull();
  await expect(page.locator('.diagram svg').first()).toBeVisible();
  report.checks.push('本地 Mermaid、子目录部署、切换保留章节和锚点');

  await page.goto(`${base}next/#/guide/channel-fixture`);
  await ready();
  await page.getByRole('link', { name: '查看发布版 →' }).click();
  await ready();
  await expect(page).toHaveURL(`${base}?docs-fallback=1#/`);
  await expect(page.getByRole('status')).toContainText('目标通道暂无原章节');
  report.checks.push('目标缺页明确回到发布通道首页');

  const search = async (query, found) => {
    await page.getByLabel('搜索文档', { exact: true }).fill(query);
    const result = page.locator('.search .matching-post');
    if (found) await expect(result.filter({ hasText: query }).first()).toBeVisible();
    else await expect(page.locator('.search .results-status')).toHaveText('没有找到结果，请换一个关键词。');
  };
  await page.goto(`${base}next/`);
  await ready();
  await search('仅开发通道检索样本', true);
  await page.locator('.search .matching-post').filter({ hasText: '仅开发通道检索样本' }).first().getByRole('link').click();
  await expect(page.locator('.markdown-section h1')).toContainText('仅开发通道检索样本');
  const oldNamespace = await page.evaluate(() => window.$docsify.search.namespace);
  await page.goto(base);
  await ready();
  await search('仅开发通道检索样本', false);
  expect(await page.evaluate(() => window.$docsify.search.namespace)).not.toBe(oldNamespace);
  activeOutput = updated;
  await page.goto(`${base}next/`);
  await ready();
  await search('更新后检索样本', true);
  await search('仅开发通道检索样本', false);
  expect(await page.evaluate(() => window.$docsify.search.namespace)).not.toBe(oldNamespace);
  report.checks.push('真实搜索命中、两通道隔离、内容变化后旧缓存失效');

  for (const channel of ['', 'next/']) {
    await page.goto(`${base}${channel}#/guide/architecture`);
    await ready();
    await expect(page.locator('.diagram svg').first()).toBeVisible();
    await page.screenshot({ path: join(work, channel ? 'development-desktop.png' : 'release-desktop.png'), fullPage: true });
    const localLinks = await page.locator('.sidebar-nav a, .markdown-section a').evaluateAll(links => links.map(link => link.href));
    for (const href of localLinks) {
      const url = new URL(href);
      if (url.origin !== origin) continue;
      if (!url.hash) {
        expect((await context.request.get(url.href)).status(), href).toBe(200);
        continue;
      }
      expect(url.pathname).toBe(`/preview/${channel}`);
      const document = url.hash.slice(1).split('?')[0].replace(/\.md$/, '') || '/';
      const filename = document === '/' ? 'README.md' : document.slice(1) + '.md';
      expect((await context.request.get(`${base}${channel}${filename}`)).status(), href).toBe(200);
    }
    for (const license of ['LICENSE', 'NOTICE']) expect((await context.request.get(`${base}${channel}${license}`)).status()).toBe(200);
    expect((await context.request.get(`${base}${channel}development/operations.md`)).status()).toBe(404);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('.docs-channel')).toBeVisible();
    await expect(page.locator('.diagram svg').first()).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: join(work, channel ? 'development-mobile.png' : 'release-mobile.png'), fullPage: true });
    await page.setViewportSize({ width: 1440, height: 1000 });
  }
  report.checks.push('两通道导航和资源、许可材料、研发文件404、桌面与窄屏图表');
  for (const [document, title, diagramCount] of [['tutorial', '应用开发实战', 3], ['catalog-reliability', '目录模块的后台与可靠交付', 3], ['scaffolding', '生成可编辑业务源码', 0]]) {
    await page.goto(`${base}next/#/guide/${document}`);
    await ready();
    await expect(page.locator('.markdown-section h1')).toHaveText(title);
    await expect(page.locator('.docs-channel-label')).toHaveText('开发版');
    report.pages ??= [];
    report.pages.push({ document, title: await page.locator('.markdown-section h1').textContent(), code: await page.locator('.markdown-section pre').evaluateAll(nodes => nodes.map(node => ({ language: node.getAttribute('data-lang'), className: node.className }))) });
    await expect(page.locator('.markdown-section pre[data-lang="php"], .markdown-section pre[data-lang="bash"], .markdown-section pre[data-lang="sh"]').first()).toBeVisible();
    await expect(page.locator('.diagram svg')).toHaveCount(diagramCount);
    for (const diagram of await page.locator('.diagram svg').all()) await expect(diagram).toBeVisible();
    if (diagramCount) {
      await page.locator('.diagram').first().screenshot({ path: join(work, `${document}-diagram.png`) });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.screenshot({ path: join(work, `${document}-mobile.png`) });
    await page.setViewportSize({ width: 1440, height: 1000 });
  }
  report.checks.push('连续教程、可靠投递续篇、脚手架代码与六张真实 Mermaid 图，窄屏无页面溢出');
  expect(report.errors).toEqual([]);
  report.status = 'passed';
} catch (error) {
  report.errors.push(String(error.stack || error));
  report.status = 'failed';
  process.exitCode = 1;
} finally {
  if (context) await context.close();
  if (browser) await browser.close();
  if (server) await new Promise(accept => { server.close(accept); server.closeAllConnections(); });
  rmSync(source, { recursive: true, force: true });
  report.cleanup = { browser: true, server: true, sourceAndExports: true };
  writeFileSync(join(work, 'verification.json'), JSON.stringify(report, null, 2) + '\n');
  console.log(JSON.stringify({ status: report.status, checks: report.checks, report: join(work, 'verification.json'), errors: report.errors }, null, 2));
}
