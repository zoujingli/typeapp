<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/tools/distribution/Process.php';
require dirname(__DIR__) . '/tools/distribution/Batch.php';
require dirname(__DIR__) . '/tools/distribution/Publisher.php';

use TypeApp\Distribution\Batch;
use TypeApp\Distribution\Process;
use TypeApp\Distribution\Publisher;

$workspace = dirname(__DIR__) . '/build/distribution-batch-' . bin2hex(random_bytes(8));
expect(mkdir($workspace, 0700), '无法创建分发验收目录');
$children = [];
try {
    $source = $workspace . '/source';
    mkdir($source);
    $origin = $workspace . '/origin';
    Process::output(['git', 'init', '--bare', $origin], $workspace);
    Process::output(['git', 'init', '-b', 'main'], $source);
    Process::output(['git', 'config', 'user.name', '分发验收'], $source);
    Process::output(['git', 'config', 'user.email', 'test@type-app.invalid'], $source);
    Process::output(['git', 'remote', 'add', 'origin', $origin], $source);
    $mapping = ['protocol' => 1, 'source-repository' => 'zoujingli/typeapp', 'packages' => []];
    $remotes = [];
    $license = file_get_contents(dirname(__DIR__) . '/LICENSE');
    $notice = file_get_contents(dirname(__DIR__) . '/NOTICE');
    expect(is_string($license) && $license !== '' && is_string($notice) && $notice !== '', '分发验收缺少许可证材料');
    file_put_contents($source . '/LICENSE', $license);
    foreach (['type-runtime', 'type-core', 'type-build'] as $name) {
        mkdir($source . '/plugin/' . $name . '/src', 0700, true);
        file_put_contents($source . '/plugin/' . $name . '/composer.json', json_encode(['name' => 'zoujingli/' . $name, 'type' => 'library', 'license' => 'Apache-2.0',
            'require' => $name === 'type-core' ? ['zoujingli/type-runtime' => '^1.0'] : []], JSON_THROW_ON_ERROR));
        file_put_contents($source . '/plugin/' . $name . '/LICENSE', $license);
        file_put_contents($source . '/plugin/' . $name . '/NOTICE', $notice);
        file_put_contents($source . '/plugin/' . $name . '/README.md', '# ' . $name);
        file_put_contents($source . '/plugin/' . $name . '/src/Value.php', "<?php\n// 固定初始内容\n");
        if ($name === 'type-build') {
            file_put_contents($source . '/plugin/type-build/NOTICE', $notice);
            mkdir($source . '/plugin/type-build/docs', 0700);
            file_put_contents($source . '/plugin/type-build/docs/operations.md', '随发布提供的操作手册');
        }
        $mapping['packages'][$name] = ['composer-name' => 'zoujingli/' . $name, 'prefix' => 'plugin/' . $name, 'repository' => 'zoujingli/' . $name, 'branch' => 'main', 'visibility' => 'public'];
        $remotes[$name] = $workspace . '/' . $name . '.git';
        Process::output(['git', 'init', '--bare', $remotes[$name]], $workspace);
    }
    file_put_contents($source . '/toolchain.lock.json', '{"protocol":1}');
    file_put_contents($source . '/private-business.txt', '未映射的私有业务');
    mkdir($source . '/app/system/controller', 0700, true);
    mkdir($source . '/config', 0700);
    mkdir($source . '/docs/build-config', 0700, true);
    file_put_contents($source . '/app/system/controller/UserController.php', "<?php\n// 仅主仓示例，不能出现在组件包中。\n");
    file_put_contents($source . '/config/app.php', "<?php\nreturn ['name' => 'private-demo'];\n");
    file_put_contents($source . '/docs/build-config/type-app.json', '{}');
    file_put_contents($source . '/.env', 'PRIVATE_CANARY=not-a-real-secret');
    Process::output(['git', 'add', '.'], $source);
    Process::output(['git', 'commit', '-m', 'feat: 创建分发验收内容'], $source);
    $first = Process::output(['git', 'rev-parse', 'HEAD'], $source);
    Process::output(['git', 'push', 'origin', 'main'], $source);
    $privateMapping = $mapping;
    $privateMapping['packages']['type-core']['visibility'] = 'private';
    $rejectedPrivate = false;
    try {
        Batch::plan($source, $first, 'branch', '', $privateMapping);
    } catch (RuntimeException $error) {
        $rejectedPrivate = str_contains($error->getMessage(), '映射超出受控范围');
    }
    expect($rejectedPrivate, '公开分发错误接受了私有目标配置');
    $plan = Batch::plan($source, $first, 'branch', '', $mapping);
    $reports = [];
    foreach ($remotes as $name => $remote) {
        $reports[$name] = Publisher::publish($source, $remote, $plan, $name);
        expect($reports[$name]['status'] === 'published', '首次分发失败');
        $again = Publisher::publish($source, $remote, $plan, $name);
        expect($again['status'] === 'already-current', '重复分发不幂等');
        $files = Process::output(['git', '--git-dir=' . $remote, 'ls-tree', '-r', '--name-only', 'main'], $workspace);
        expect(!str_contains($files, 'private-business') && !str_contains($files, 'plugin/'), '分发泄漏未映射内容');
        expect(!preg_match('~(?:^|\n)(?:app/|config/|\.env(?:\n|$))~', $files), '标准应用、配置或dotenv泄漏到组件子仓');
        expect(str_contains($files, "LICENSE\n") || str_ends_with($files, 'LICENSE'), '组件分发遗漏 LICENSE');
        expect(str_contains($files, "NOTICE\n") || str_ends_with($files, 'NOTICE'), '组件分发遗漏 NOTICE');
        expect($name !== 'type-build' || str_contains($files, 'docs/operations.md'), '构建组件遗漏发布所需操作手册');
    }
    expect(Batch::collect($plan, $reports)['complete'], '完整批次汇总失败');
    foreach (['batch' => str_repeat('0', 64), 'source' => str_repeat('0', 40), 'split' => str_repeat('0', 40),
        'repository' => 'zoujingli/type-runtime', 'package' => 'zoujingli/type-runtime', 'mode' => 'tag',
        'version' => 'v1.0.0', 'reference' => 'refs/tags/v1.0.0'] as $field => $different) {
        foreach ([false, true] as $missing) {
            $invalid = $reports;
            if ($missing) {
                unset($invalid['type-core'][$field]);
            } else {
                $invalid['type-core'][$field] = $different;
            }
            expect(!Batch::collect($plan, $invalid)['complete'], '批次汇总接受缺失或不一致的字段：' . $field);
        }
    }
    $tag = Batch::plan($source, $first, 'tag', 'v1.0.0', $mapping);
    $tagReports = [];
    foreach ($remotes as $name => $remote) {
        $tagReports[$name] = Publisher::publish($source, $remote, $tag, $name);
        expect($tagReports[$name]['status'] === 'published', '稳定标签创建失败');
    }
    expect(Batch::collect($tag, $tagReports)['complete'], '标签批次汇总失败');
    $wrongTag = $tagReports;
    $wrongTag['type-core']['reference'] = 'refs/heads/main';
    expect(!Batch::collect($tag, $wrongTag)['complete'], '标签批次接受了分支引用');
    file_put_contents($source . '/plugin/type-core/src/Value.php', "<?php\n// 第二次核心内容\n");
    Process::output(['git', 'add', '.'], $source);
    Process::output(['git', 'commit', '-m', 'feat(core): 更新验收内容'], $source);
    $second = Process::output(['git', 'rev-parse', 'HEAD'], $source);
    $next = Batch::plan($source, $second, 'branch', '', $mapping);
    $partial = ['type-runtime' => Publisher::publish($source, $remotes['type-runtime'], $next, 'type-runtime'),
        'type-build' => Publisher::publish($source, $remotes['type-build'], $next, 'type-build'),
        'type-core' => Publisher::publish($source, $workspace . '/missing.git', $next, 'type-core')];
    expect(!Batch::collect($next, $partial)['complete'] && $partial['type-runtime']['status'] === 'already-current', '部分失败被误报完成');
    expect(Batch::collect($next, $partial)['items']['type-core'] === $partial['type-core'], '汇总丢失了当前批次的真实失败诊断');
    $partial['type-core'] = Publisher::publish($source, $remotes['type-core'], $next, 'type-core');
    expect(Batch::collect($next, $partial)['complete'], '重跑没有补齐缺项');
    expect(Publisher::publish($source, $remotes['type-core'], $tag, 'type-core')['status'] === 'already-current', '分支前进影响已完成稳定批次的幂等重跑');
    $conflict = Batch::plan($source, $second, 'tag', 'v1.0.0', $mapping);
    expect(Publisher::publish($source, $remotes['type-core'], $conflict, 'type-core')['status'] === 'failed', '移动稳定标签没有拒绝');
    expect(Process::output(['git', '--git-dir=' . $remotes['type-core'], 'rev-parse', 'refs/tags/v1.0.0'], $workspace) === $tag['items']['type-core']['split'], '旧标签被改写');
    expect(Publisher::publish($source, $remotes['type-core'], $plan, 'type-core')['status'] === 'failed', '过期批次覆盖了新子仓');
    $race = Batch::plan($source, $second, 'tag', 'v1.0.1', $mapping);
    for ($index = 0; $index < 2; $index++) {
        $pid = pcntl_fork();
        expect($pid !== -1, '无法启动分发竞争进程');
        if ($pid === 0) {
            $result = Publisher::publish($source, $remotes['type-core'], $race, 'type-core');
            exit(in_array($result['status'], ['published', 'already-current'], true) ? 0 : 1);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status) === 0, '相同标签的竞争执行不幂等');
    }
    $other = $workspace . '/other';
    Process::output(['git', 'clone', '-b', 'main', $remotes['type-core'], $other], $workspace);
    Process::output(['git', 'config', 'user.name', '偏离验收'], $other);
    Process::output(['git', 'config', 'user.email', 'test@type-app.invalid'], $other);
    file_put_contents($other . '/README.md', '子仓独立修改');
    Process::output(['git', 'add', '.'], $other);
    Process::output(['git', 'commit', '-m', 'test: 子仓偏离'], $other);
    Process::output(['git', 'push', 'origin', 'main'], $other);
    expect(Publisher::publish($source, $remotes['type-core'], $next, 'type-core')['status'] === 'failed', '子仓偏离没有拒绝');
    file_put_contents($source . '/plugin/type-build/docs/private-notes.md', '未登记的内部说明');
    Process::output(['git', 'add', '.'], $source);
    Process::output(['git', 'commit', '-m', 'test: 拒绝未登记的组件说明'], $source);
    $manualRejected = false;
    try {
        Batch::plan($source, Process::output(['git', 'rev-parse', 'HEAD'], $source), 'branch', '', $mapping);
    } catch (Throwable $error) {
        $manualRejected = str_contains($error->getMessage(), '未允许内容');
    }
    expect($manualRejected, '操作手册白名单放开了其他内部文档');
    Process::output(['git', 'rm', 'plugin/type-build/docs/private-notes.md'], $source);
    Process::output(['git', 'commit', '-m', 'test: 移除本轮拒绝哨兵'], $source);
    file_put_contents($source . '/plugin/type-core/auth.json', '{"token":"test-only"}');
    Process::output(['git', 'add', '.'], $source);
    Process::output(['git', 'commit', '-m', 'test: 拒绝未允许文件'], $source);
    $rejected = false;
    try {
        Batch::plan($source, Process::output(['git', 'rev-parse', 'HEAD'], $source), 'branch', '', $mapping);
    } catch (Throwable $error) {
        $rejected = str_contains($error->getMessage(), '未允许内容');
    }
    expect($rejected, '未允许的包内容没有拒绝');
    echo "分发批次真实 Git 验证通过：重跑、报告身份、部分失败、标签不可移动、竞争、子仓偏离和内容隔离。\n";
} finally {
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($workspace);
}
