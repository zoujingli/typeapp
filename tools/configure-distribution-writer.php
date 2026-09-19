<?php

declare(strict_types=1);

require __DIR__ . '/distribution/Process.php';
use TypeApp\Distribution\Process;

$temporary = null;
try {
    $environment = (string) getenv('GITHUB_ENV');
    if ($environment === '') {
        throw new RuntimeException('模板写入凭据加载入口仅用于当前 Actions 任务');
    }
    $root = dirname(__DIR__);
    $templateKey = getenv('TYPE_PROJECT_DEPLOY_KEY');
    if ($templateKey === false || $templateKey === '') {
        throw new RuntimeException('缺少模板仓库专用写入凭据');
    }
    $temporary = Process::output(['mktemp', '-d'], $root);
    $known = $temporary . '/known_hosts';
    file_put_contents($known, Process::output(['gh', 'api', 'meta', '--jq', '.ssh_keys[] | "github.com " + .'], $root) . "\n");
    // 公开组件通过 HTTPS 读取，SSH 仅持有模板子仓的专用写入权限。
    $path = $temporary . '/type-project';
    file_put_contents($path, $templateKey);
    chmod($path, 0600);
    $config = "Host github.com\n  HostName github.com\n  User git\n  IdentitiesOnly yes\n  BatchMode yes\n  StrictHostKeyChecking yes\n  ConnectTimeout 15\n  IdentityFile " . $path . "\n  UserKnownHostsFile " . $known . "\n";
    $file = $temporary . '/config';
    file_put_contents($file, $config);
    chmod($file, 0600);
    file_put_contents($environment, 'GIT_SSH_COMMAND=ssh -F ' . $file . "\nTYPE_DISTRIBUTION_WRITER_DIRECTORY=" . $temporary . "\n", FILE_APPEND);
    echo "当前任务的模板专用写入凭据已准备。\n";
} catch (Throwable $error) {
    if ($temporary !== null) {
        foreach (new DirectoryIterator($temporary) as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                unlink($entry->getPathname());
            }
        }
        rmdir($temporary);
    }
    fwrite(STDERR, '模板写入凭据加载失败：' . $error->getMessage() . "\n");
    exit(1);
}
