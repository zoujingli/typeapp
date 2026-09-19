(function () {
  'use strict';

  const siteTitle = 'TypeApp - 物联开源分享';

  function mountCodePreview() {
    const preview = document.querySelector('.code-preview');
    if (!preview) return function () {};

    const code = preview.querySelector('.preview-code');
    const command = preview.querySelector('.preview-command');
    const button = preview.querySelector('.preview-toggle');
    const status = preview.querySelector('.preview-status');
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const source = code.textContent;
    const commandText = command.textContent;

    if (window.Prism && window.Prism.languages.php) {
      code.innerHTML = window.Prism.highlight(source, window.Prism.languages.php, 'php');
    }
    // 保留高亮节点，只逐步显示文本，避免每个字符都重新解析代码。
    const walker = document.createTreeWalker(code, NodeFilter.SHOW_TEXT);
    const tokens = [];
    let offset = 0;
    while (walker.nextNode()) {
      const node = walker.currentNode;
      tokens.push({ node: node, text: node.data, start: offset });
      offset += node.data.length;
    }

    const commandStart = source.length + 16;
    const flowStart = commandStart + commandText.length;
    const end = flowStart + 42;
    let position = reducedMotion.matches ? end : 0;
    let timer = null;
    let paused = false;
    let visible = false;

    function draw() {
      tokens.forEach(function (token) {
        const text = token.text.slice(0, Math.max(0, position - token.start));
        if (token.node.data !== text) token.node.data = text;
      });
      command.textContent = commandText.slice(0, Math.max(0, position - commandStart));
      preview.dataset.phase = position < commandStart ? 'source' : position < flowStart ? 'build' : 'native';
      status.textContent = position < commandStart ? '01 / 路由声明' : position < flowStart ? '02 / 构建命令' : '03 / 编译路径演示';
    }

    function sync() {
      window.clearTimeout(timer);
      const complete = position >= end;
      const running = !paused && visible && !document.hidden && !reducedMotion.matches;
      preview.dataset.motion = complete ? 'complete' : running ? 'running' : 'paused';
      button.hidden = reducedMotion.matches;
      button.textContent = paused ? '继续动效' : '暂停动效';
      if (running) {
        timer = window.setTimeout(function () {
          position = complete ? 0 : position + 1;
          draw();
          sync();
        }, complete ? 2000 : 40);
      }
    }

    function toggle() {
      paused = !paused;
      sync();
    }

    function motionChanged() {
      if (reducedMotion.matches) {
        position = end;
        draw();
      }
      sync();
    }

    // 离开可见区域或切换标签页时停止计时；换页时释放所有监听。
    const observer = new IntersectionObserver(function (entries) {
      visible = entries[0].isIntersecting;
      sync();
    }, { threshold: 0.1 });
    observer.observe(preview);
    button.addEventListener('click', toggle);
    reducedMotion.addEventListener('change', motionChanged);
    document.addEventListener('visibilitychange', sync);
    draw();
    sync();

    return function () {
      window.clearTimeout(timer);
      observer.disconnect();
      button.removeEventListener('click', toggle);
      reducedMotion.removeEventListener('change', motionChanged);
      document.removeEventListener('visibilitychange', sync);
    };
  }

  window.$docsify = {
    name: 'TypeApp',
    nameLink: '#/',
    homepage: 'README.md',
    loadSidebar: true,
    loadNavbar: true,
    alias: {
      '/.*/_sidebar.md': '/_sidebar.md',
      '/.*/_navbar.md': '/_navbar.md',
    },
    routerMode: 'hash',
    relativePath: true,
    auto2top: true,
    subMaxLevel: 2,
    notFoundPage: '_404.md',
    mergeNavbar: true,
    executeScript: false,
    noEmoji: true,
    skipLink: '跳到正文',
    pageTitleFormatter: function () { return siteTitle; },
    search: {
      paths: 'auto',
      placeholder: '搜索文档',
      noData: '没有找到结果，请换一个关键词。',
      depth: 3,
      maxAge: 3600000,
      // 集中更新文档时递增版本，避免读者继续使用旧章节索引。
      namespace: 'typeapp-guide-v25-' + window.location.pathname,
    },
    plugins: [function (hook) {
      let disposePreview = function () {};
      let mermaidReady = false;

      function themeValue(name, fallback) {
        const value = window.getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return value || fallback;
      }

      function ensureMermaid() {
        if (!window.mermaid) return false;
        if (mermaidReady) return true;
        window.mermaid.initialize({
          startOnLoad: false,
          securityLevel: 'strict',
          theme: 'base',
          look: 'classic',
          fontFamily: themeValue('--font-family', 'sans-serif'),
          flowchart: {
            curve: 'basis',
            padding: 12,
            nodeSpacing: 32,
            rankSpacing: 44,
            htmlLabels: false,
            useMaxWidth: true,
          },
          sequence: {
            actorMargin: 28,
            boxMargin: 6,
            messageMargin: 32,
            mirrorActors: false,
            useMaxWidth: true,
            diagramMarginX: 8,
            diagramMarginY: 10,
          },
          themeVariables: {
            fontSize: '14px',
            primaryColor: themeValue('--brand-soft', '#e4f3ed'),
            primaryTextColor: themeValue('--ink', '#16382e'),
            primaryBorderColor: themeValue('--brand', '#147d64'),
            lineColor: themeValue('--brand', '#147d64'),
            secondaryColor: themeValue('--surface-muted', '#eff5f2'),
            tertiaryColor: themeValue('--surface-raised', '#ffffff'),
            background: themeValue('--surface', '#f8fbfa'),
            mainBkg: themeValue('--surface-raised', '#ffffff'),
            nodeBorder: themeValue('--brand-line', '#b5d9cc'),
            clusterBkg: themeValue('--surface-muted', '#eff5f2'),
            clusterBorder: themeValue('--brand-line', '#b5d9cc'),
            titleColor: themeValue('--ink', '#16382e'),
            edgeLabelBackground: themeValue('--surface', '#f8fbfa'),
            actorBkg: themeValue('--surface-raised', '#ffffff'),
            actorBorder: themeValue('--brand', '#147d64'),
            actorTextColor: themeValue('--ink', '#16382e'),
            actorLineColor: themeValue('--brand-line', '#b5d9cc'),
            signalColor: themeValue('--brand', '#147d64'),
            signalTextColor: themeValue('--ink', '#16382e'),
            labelBoxBkgColor: themeValue('--brand-soft', '#e4f3ed'),
            labelBoxBorderColor: themeValue('--brand', '#147d64'),
            labelTextColor: themeValue('--ink', '#16382e'),
            loopTextColor: themeValue('--ink', '#16382e'),
            noteBkgColor: themeValue('--brand-soft', '#e4f3ed'),
            noteTextColor: themeValue('--ink', '#16382e'),
            noteBorderColor: themeValue('--brand-line', '#b5d9cc'),
            activationBkgColor: themeValue('--brand-soft', '#e4f3ed'),
            activationBorderColor: themeValue('--brand', '#147d64'),
            sequenceNumberColor: themeValue('--surface-raised', '#ffffff'),
            tertiaryTextColor: themeValue('--text', '#425d54'),
          },
        });
        mermaidReady = true;
        return true;
      }

      function mermaidBlocks() {
        const found = [];
        document.querySelectorAll('.markdown-section pre[data-lang="mermaid"]').forEach(function (pre) {
          found.push(pre);
        });
        document.querySelectorAll('.markdown-section pre > code.lang-mermaid, .markdown-section pre > code.language-mermaid').forEach(function (code) {
          const pre = code.parentElement;
          if (pre && found.indexOf(pre) === -1) found.push(pre);
        });
        return found;
      }

      function mountOrbitDiagrams() {
        const hosts = document.querySelectorAll('.orbit-diagram[data-src]');
        return Promise.all(Array.from(hosts).map(function (host) {
          if (host.querySelector('svg.orbit-svg')) return Promise.resolve();
          const src = host.getAttribute('data-src');
          if (!src) return Promise.resolve();
          return fetch(src).then(function (response) {
            if (!response.ok) throw new Error('orbit-diagram');
            return response.text();
          }).then(function (markup) {
            const doc = new DOMParser().parseFromString(markup, 'image/svg+xml');
            const svg = doc.documentElement;
            if (!svg || svg.nodeName.toLowerCase() !== 'svg' || doc.querySelector('parsererror')) return;
            svg.classList.add('orbit-svg');
            svg.setAttribute('role', 'img');
            const img = host.querySelector('img');
            if (img) img.replaceWith(svg);
            else host.prepend(svg);
          }).catch(function () {});
        }));
      }

      function renderDiagrams() {
        if (!ensureMermaid()) return Promise.resolve();
        const blocks = mermaidBlocks();
        if (!blocks.length) return Promise.resolve();
        const nodes = [];
        blocks.forEach(function (pre) {
          const code = pre.querySelector('code');
          const source = (code ? code.textContent : pre.textContent).replace(/^\s+|\s+$/g, '');
          const figure = document.createElement('figure');
          figure.className = 'diagram';
          figure.setAttribute('role', 'img');
          figure.setAttribute('aria-label', '结构示意图');
          const host = document.createElement('div');
          host.className = 'mermaid';
          host.textContent = source;
          figure.appendChild(host);
          pre.replaceWith(figure);
          nodes.push(host);
        });
        return window.mermaid.run({ nodes: nodes }).catch(function () {
          nodes.forEach(function (host) {
            if (!host.querySelector('svg')) host.classList.add('diagram-error');
          });
        });
      }

      function addCopyButtons() {
        document.querySelectorAll('.markdown-section pre > code').forEach(function (code) {
          const pre = code.parentElement;
          if (!pre || pre.getAttribute('data-lang') === 'mermaid' || pre.querySelector('.copy-code')) {
            return;
          }
          const button = document.createElement('button');
          button.type = 'button';
          button.className = 'copy-code';
          button.textContent = '复制代码';
          button.setAttribute('aria-live', 'polite');
          button.addEventListener('click', async function () {
            try {
              await navigator.clipboard.writeText(code.textContent);
              button.textContent = '已复制';
            } catch (_) {
              const selection = window.getSelection();
              const range = document.createRange();
              range.selectNodeContents(code);
              selection.removeAllRanges();
              selection.addRange(range);
              button.textContent = '已选中，请手动复制';
            }
            window.setTimeout(function () { button.textContent = '复制代码'; }, 2000);
          });
          pre.appendChild(button);
        });
      }

      hook.beforeEach(function (markdown) {
        disposePreview();
        return markdown;
      });
      hook.doneEach(function () {
        const content = document.querySelector('.content');
        const footer = document.querySelector('.site-footer');
        if (content && footer) content.appendChild(footer);

        const sidebar = document.querySelector('.sidebar');
        const brand = document.querySelector('.app-name');
        if (sidebar && brand && sidebar.firstElementChild !== brand) {
          sidebar.prepend(brand);
        }

        const toggle = document.querySelector('.sidebar-toggle');
        if (toggle) {
          toggle.setAttribute('aria-label', '切换文档导航');
          toggle.setAttribute('title', '切换文档导航');
          const toggleButton = toggle.querySelector('.sidebar-toggle-button');
          if (toggleButton) toggleButton.setAttribute('aria-label', '切换文档导航');
        }
        const searchInput = document.querySelector('.search input');
        if (searchInput) searchInput.setAttribute('aria-label', '搜索文档');
        const clearSearch = document.querySelector('.search .clear-button');
        if (clearSearch) {
          clearSearch.setAttribute('aria-label', '清空搜索');
          clearSearch.setAttribute('title', '清空搜索');
        }

        return mountOrbitDiagrams().then(renderDiagrams).then(function () {
          addCopyButtons();
          disposePreview();
          disposePreview = mountCodePreview();
        });
      });
    }],
  };
}());
