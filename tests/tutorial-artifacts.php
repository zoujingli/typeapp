<?php

declare(strict_types=1);

/**
 * 只保全本轮消费者的程序、诊断及最小输入，不复制 SDK、vendor 或数据库。
 * 调用方仍持有原目录；任一复制或摘要核对失败时必须保留原件，不能继续清理。
 * @param list<string> $paths 相对来源目录的明确文件或目录。
 * @return array<string,array{sha256:string,bytes:int}>
 */
function copyTutorialArtifacts(string $source, string $destination, array $paths): array
{
    expect(is_dir($source) && !is_link($source), '教程证据来源必须是本轮真实目录');
    expect(!str_starts_with($destination . '/', $source . '/'), '证据目录不能位于待清理来源中');
    $files = [];
    foreach ($paths as $relative) {
        expect($relative !== '' && !str_starts_with($relative, '/') && !str_contains($relative, '..') && !str_contains($relative, '\\'), '教程证据路径越界');
        $path = $source . '/' . $relative;
        expect(!is_link($path), '教程证据不能跟随链接');
        if (is_file($path)) {
            $files[$relative] = $path;
        } elseif (is_dir($path)) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $entry) {
                expect(!$entry->isLink(), '教程证据不能跟随链接');
                if ($entry->isFile()) {
                    $files[str_replace('\\', '/', substr($entry->getPathname(), strlen($source) + 1))] = $entry->getPathname();
                }
            }
        }
    }
    ksort($files);
    $manifest = [];
    foreach ($files as $relative => $path) {
        $target = $destination . '/' . $relative;
        $directory = dirname($target);
        expect(is_dir($directory) || mkdir($directory, 0700, true), '无法创建教程证据目录');
        expect(!is_link($target), '教程证据目标不能是链接');
        $digest = hash_file('sha256', $path);
        $bytes = filesize($path);
        expect(is_string($digest) && is_int($bytes), '无法读取教程原始证据');
        if (!file_exists($target)) {
            expect(copy($path, $target), '无法复制教程原始证据');
        }
        clearstatcache(true, $target);
        expect(is_file($target) && filesize($target) === $bytes && hash_file('sha256', $target) === $digest
            && hash_file('sha256', $path) === $digest, '教程证据摘要不一致，保留消费者原件');
        $manifest[$relative] = ['sha256' => $digest, 'bytes' => $bytes];
    }
    return $manifest;
}

/** 失败与正常收尾共用同一保全门槛；只在所有现存证据逐字节摘要一致后删除消费者。 */
function archiveTutorialConsumer(string $consumer, string $destination, string $root): array
{
    $paths = ['composer.json', 'composer.lock', 'type-app.json', 'catalog-candidate.json', 'verification.json',
        'deployment.json', 'deployment-path.json', '.env.example', 'prepare.php', 'dev.php', 'configure.php',
        'app', 'config', 'tests', 'deployment-evidence', 'build/type-project', 'build/type-project.exe'];
    foreach (glob($consumer . '/*.log') ?: [] as $path) {
        $paths[] = basename($path);
    }
    if (is_dir($consumer . '/build')) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($consumer . '/build', FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($consumer) + 1));
            // 事务/缓存教程只保全当前编译尝试的相关转译文本，不复制对象、SDK 或完整 vendor。
            $generatedPhp = in_array($relative, ['build/compiler/generated-operations.php', 'build/compiler/assembled-application.php'], true);
            $generatedCpp = preg_match(
                '~^build/compiler/attempts/[a-f0-9]{8}/(?:'
                . '(?:app/catalog/(?:service/Reliability|event/(?:AuditChanged|ProductChanged))|vendor/zoujingli/type-orm/src/Connection|generated-operations|assembled-application)\\.cc'
                . '|include/php_[A-Za-z0-9_]*(?:app_catalog_service_Reliability|app_catalog_event_(?:AuditChanged|ProductChanged)|vendor_zoujingli_type_orm_src_Connection|generated_operations|assembled_application)_[a-f0-9]{10}_decl\\.h)$~D',
                $relative
            ) === 1;
            if ($file->isFile() && (str_ends_with($file->getFilename(), '.log') || str_ends_with($file->getFilename(), '.build.json')
                || $file->getFilename() === 'project.yml' || $generatedPhp || $generatedCpp)) {
                $paths[] = $relative;
            }
        }
    }
    $record = ['consumer' => $consumer, 'files' => copyTutorialArtifacts($consumer, $destination, $paths)];
    if (is_file($consumer . '/deployment-path.json')) {
        $deployment = json_decode(file_get_contents($consumer . '/deployment-path.json'), true, 32, JSON_THROW_ON_ERROR);
        $base = $deployment['base'] ?? '';
        expect(($deployment['project'] ?? null) === $consumer
            && preg_match('~^' . preg_quote($root, '~') . '/build/single program-[a-f0-9]{12}$~D', $base) === 1, '部署证据不属于本轮消费者');
        if (is_dir($base)) {
            $record['deployment-files'] = copyTutorialArtifacts(
                $base,
                $destination . '/deployment',
                ['program only', 'tutorial.log', 'tutorial-smoke-process.json', 'tutorial-catalog-process.json', 'verification.json']
            );
            // 中断时不能证明部署所有者已释放进程或 Windows ACL；回执保留准确路径，禁止上层盲删。
            $record['retained-deployment'] = $base;
        }
    }
    expect(is_dir($destination) || mkdir($destination, 0700, true), '无法建立教程保全目录');
    $receipt = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    expect(file_put_contents($destination . '/preservation.json', $receipt) === strlen($receipt)
        && file_get_contents($destination . '/preservation.json') === $receipt, '教程证据回执保全失败');
    removeTestDirectory($consumer);
    return $record;
}
