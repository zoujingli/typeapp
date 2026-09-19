<?php

declare(strict_types=1);

/**
 * 文档与实现一致性检查。
 *
 * 确认描述当前实现的文档里出现的应用命令、HTTP 接口与 Composer 脚本，在源码中确实存在，
 * 避免文档描述已删除或已改名的实现。文档明确记录「某实现已移除」的表述不计为失效，
 * 前端菜单路径也不按 HTTP 接口判定。
 */

require __DIR__ . '/support.php';

$root = dirname(__DIR__);

// 文档明确记录实现已移除时的语义标记：同一行命中则跳过该引用。
$removedHints = ['已移除', '已删除', '已废弃', '不再提供', '不再支持', '不再使用', '已下线'];

/** 递归收集指定目录下符合条件的文件，返回相对于仓库根的路径。 */
function docsCheckFiles(string $root, array $directories, array $extensions): array
{
    $files = [];
    foreach ($directories as $directory) {
        $base = $root . '/' . $directory;
        if (!is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $files[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }
    sort($files);

    return $files;
}

/** 路径归一化：把参数占位符统一为 {}，便于跨文档比对。占位符名称不属于契约。 */
function docsCheckPath(string $path): string
{
    $normalized = preg_replace('/\{[^}]+\}/', '{}', $path) ?? $path;
    $normalized = rtrim($normalized, '/');

    return $normalized === '' ? '/' : $normalized;
}

// ---------- 代码侧：路由、命令、菜单路径与 Composer 脚本 ----------

$routes = [];
$menus = [];
$commands = [];

foreach (docsCheckFiles($root, ['app', 'bin', 'config', 'plugin', 'templates'], ['php']) as $file) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        continue;
    }

    // 控制器路由：Group 前缀与 Route 路径直接拼接（Route 已以 / 开头时不再补斜杠）。
    $prefix = '';
    if (preg_match("/#\\[Group\\(prefix:\\s*'([^']+)'/", $source, $group) === 1) {
        $prefix = rtrim($group[1], '/');
    }
    if (preg_match_all("/#\\[Route\\(\\s*'([^']+)'/", $source, $matches) > 0) {
        foreach ($matches[1] as $route) {
            $full = $prefix === ''
                ? $route
                : $prefix . (str_starts_with($route, '/') ? $route : '/' . $route);
            $routes[docsCheckPath($full)] = true;
        }
    }

    // 前端菜单路径由权限服务下发，不是 HTTP 接口。
    if (preg_match_all("/'path'\\s*=>\\s*'(\\/[^']+)'/", $source, $matches) > 0) {
        foreach ($matches[1] as $path) {
            $menus[docsCheckPath($path)] = true;
        }
    }

    // 应用命令：源码中出现的 ns:cmd 字面量即视为已注册。
    if (preg_match_all("/'([a-z][a-z0-9]*:[a-z][a-z0-9-]*)'/", $source, $matches) > 0) {
        foreach ($matches[1] as $command) {
            $commands[$command] = true;
        }
    }
}

// 前端页面路由同样会出现在文档中，与菜单 path 一并作为已知的界面路径。
// 文档里的 `/admin/login`、`/broker/login` 等页面地址没有后端路由与菜单项，属正常。
$routerSource = $root . '/web/apps/web-antd/src/router.ts';
if (is_file($routerSource)) {
    $router = (string) file_get_contents($routerSource);
    if (preg_match_all("/path:\\s*'([^']+)'/", $router, $matches) > 0) {
        foreach ($matches[1] as $pagePath) {
            $normalized = docsCheckPath($pagePath);
            if ($normalized === '/') {
                continue;
            }
            $menus[$normalized] = true;
            if (!str_starts_with($normalized, '/')) {
                $menus['/' . $normalized] = true;
            }
        }
    }
}

$manifest = json_decode((string) file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);
$scripts = array_keys((array) ($manifest['scripts'] ?? []));

// 文档中的 `test:*`、`typeapp:*` 形式同样指 Composer 脚本，一并纳入已知命令。
foreach ($scripts as $script) {
    $commands[$script] = true;
}

// 命令命名空间由源码注册推导，并补上主仓脚本使用的命名空间。
// 文档里的 `node:sqlite`、`sha256:...` 等外部模块或摘要标识因此不按应用命令判定。
$namespaces = ['test' => true, 'typeapp' => true, 'build' => true];
foreach (array_keys($commands) as $command) {
    $namespaces[explode(':', $command, 2)[0]] = true;
}

// ---------- 文档侧：逐行判定 ----------

$failures = [];
$checked = 0;

// 除 docs/ 之外，根文档、web 文档与各组件 README 同样描述实现，一并核对。
$documents = docsCheckFiles($root, ['docs'], ['md']);
foreach (['/*.md', '/web/*.md', '/plugin/*/README.md', '/plugin/*/docs/*.md'] as $pattern) {
    foreach (glob($root . $pattern) ?: [] as $file) {
        $documents[] = substr($file, strlen($root) + 1);
    }
}
$documents = array_values(array_unique($documents));
sort($documents);

foreach ($documents as $document) {
    $lines = explode("\n", (string) file_get_contents($root . '/' . $document));
    foreach ($lines as $index => $line) {
        $recorded = false;
        foreach ($removedHints as $hint) {
            if (str_contains($line, $hint)) {
                $recorded = true;
                break;
            }
        }
        if ($recorded) {
            continue;
        }

        $location = $document . ':' . ($index + 1);

        // 文档内相对链接。以 / 开头的走站点路由根，导出后从站点根解析。
        if (preg_match_all('/\]\(([^)\s]+)\)/', $line, $matches) > 0) {
            foreach ($matches[1] as $target) {
                if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')
                    || str_starts_with($target, '#') || str_starts_with($target, 'mailto:')) {
                    continue;
                }
                $clean = preg_split('/[#?]/', $target)[0];
                if ($clean === '') {
                    continue;
                }
                // 导出脚本会把根许可证材料复制到站点根，链接在导出站点中有效。
                if (in_array(basename($clean), ['LICENSE', 'NOTICE'], true)) {
                    continue;
                }
                $checked++;
                $candidate = str_starts_with($clean, '/')
                    ? $root . '/docs' . $clean
                    : dirname($root . '/' . $document) . '/' . $clean;
                if (!file_exists($candidate) && !file_exists($root . '/' . $clean)) {
                    $failures[] = $location . ' 链接失效：' . $target;
                }
            }
        }

        // 文档中直接引用的仓库脚本。
        if (preg_match_all(
            '/\b(?:php|bash)\s+((?:tests|tools)\/[A-Za-z0-9._\/-]+\.(?:php|sh))/',
            $line,
            $matches
        ) > 0) {
            foreach ($matches[1] as $script) {
                $checked++;
                if (!is_file($root . '/' . $script)) {
                    $failures[] = $location . ' 脚本不存在：' . $script;
                }
            }
        }

        // 应用命令
        if (preg_match_all('/`([a-z][a-z0-9]*:[a-z][a-z0-9-]*)`/', $line, $matches) > 0) {
            foreach ($matches[1] as $command) {
                if (!isset($namespaces[explode(':', $command, 2)[0]])) {
                    continue;
                }
                $checked++;
                if (!isset($commands[$command])) {
                    $failures[] = $location . ' 命令未注册：' . $command;
                }
            }
        }

        // HTTP 接口（含 `GET /path` 形式）
        if (preg_match_all(
            '/`(?:(?:GET|POST|PATCH|PUT|DELETE|HEAD|OPTIONS)\s+)?(\/(?:admin|customer|iot|broker|public)\/[A-Za-z0-9\/_{}.-]+)`/',
            $line,
            $matches
        ) > 0) {
            foreach ($matches[1] as $path) {
                $checked++;
                $normalized = docsCheckPath($path);
                if (isset($routes[$normalized]) || isset($menus[$normalized])) {
                    continue;
                }
                $hit = false;
                foreach ($routes as $route => $_) {
                    if (str_starts_with($route, $normalized . '/')) {
                        $hit = true;
                        break;
                    }
                }
                if (!$hit) {
                    $failures[] = $location . ' 接口不存在：' . $path;
                }
            }
        }

        // Composer 脚本：只对带完整命名空间冒号的名称判定，`composer test:*`
        // 这类通配写法与 `composer install` 这类内置命令都不参与。
        if (preg_match_all('/composer\s+([a-z][a-z0-9_-]*(?::[a-z0-9_-]+)+)/', $line, $matches) > 0) {
            foreach ($matches[1] as $script) {
                $checked++;
                if (!in_array($script, $scripts, true)) {
                    $failures[] = $location . ' Composer 脚本不存在：composer ' . $script;
                }
            }
        }
    }
}

// 测试、工具与示例同样会调用应用命令，一并核对。只认执行位置的命令，
// 不把 `'resource_scope' => 'iot:resource-a'` 这类普通字符串当成命令。
$callPattern = '/(?:identityCommand|\$run|nativeCommand|Process)\(\s*\[[^\]]*?[\'"]((?:iot|app|broker):[a-z][a-z0-9-]*)[\'"]/';

// 命令已移除或改名，而调用方尚未按迁移计划同步。显式记录而不是静默跳过，
// 以便定位与收口；新增同类引用仍会被检查拦截。
$pendingCommandCallers = [
    'iot:user' => '已移除旧 iot:user 开通入口，测试待迁移',
    'iot:support-clean' => '已移除支持授权，测试待迁移',
    'iot:audit-clean' => '已改名为 app:audit-clean 且新增 realm 参数，测试待迁移',
];

foreach (docsCheckFiles($root, ['tests', 'tools', 'examples'], ['php', 'sh', 'mjs']) as $source) {
    $text = (string) file_get_contents($root . '/' . $source);
    if (preg_match_all($callPattern, $text, $matches) === 0) {
        continue;
    }
    foreach ($matches[1] as $command) {
        $checked++;
        if (isset($pendingCommandCallers[$command]) || isset($commands[$command])) {
            continue;
        }
        $failures[] = $source . ' 调用了未注册的命令：' . $command;
    }
}

expect(
    $failures === [],
    sprintf(
        "文档与实现不一致，共 %d 处：\n%s",
        count($failures),
        implode("\n", $failures)
    )
);

printf(
    "文档与实现一致性检查通过：核对 %d 处引用，覆盖 %d 条路由、%d 个命令、%d 个 Composer 脚本。\n",
    $checked,
    count($routes),
    count($commands),
    count($scripts)
);
