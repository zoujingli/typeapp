<?php

declare(strict_types=1);

require __DIR__ . '/distribution/Process.php';
use TypeApp\Distribution\Process;

/** 显式配置公开分发目标；密钥只经 stdin 送往 GitHub，不打印或写入仓库。 */
function provisionSecret(string $root, string $name, string $file): void
{
    $output = tmpfile();
    try {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $process = proc_open(['gh', 'secret', 'set', $name, '--repo', 'zoujingli/typeapp'], [0 => ['file', $file, 'r'], 1 => $output, 2 => $output], $pipes, $root);
            if (is_resource($process) && proc_close($process) === 0) {
                return;
            }
        }
        throw new RuntimeException('分发密钥连续三次无法保存到 GitHub Actions：' . $name);
    } finally {
        if (is_resource($output)) {
            fclose($output);
        }
    }
}
try {
    if (($argv[1] ?? '') !== '--create-public-targets' || count($argv) !== 2) {
        throw new InvalidArgumentException('需要显式传入 --create-public-targets 以创建映射中的公开仓库及专用写入凭据');
    }
    $root = dirname(__DIR__);
    $mapping = json_decode(file_get_contents($root . '/.github/distribution.json'), true, 512, JSON_THROW_ON_ERROR);
    $templateFile = $root . '/.github/template-distribution.json';
    if (is_file($templateFile)) {
        $template = json_decode(file_get_contents($templateFile), true, 512, JSON_THROW_ON_ERROR);
        if (($template['prefix'] ?? '') !== 'templates/type-project' || ($template['composer-name'] ?? '') !== 'zoujingli/type-project') {
            throw new RuntimeException('模板分发配置无效');
        }
        $mapping['packages']['type-project'] = $template;
    }
    $known = json_decode(Process::output(['gh', 'secret', 'list', '--repo', 'zoujingli/typeapp', '--json', 'name'], $root), true, 512, JSON_THROW_ON_ERROR);
    $known = array_column($known, 'name');
    foreach ($mapping['packages'] as $name => $package) {
        if (!preg_match('/^type-[a-z0-9-]+$/D', $name) || $package['repository'] !== 'zoujingli/' . $name || $package['visibility'] !== 'public') {
            throw new RuntimeException('公开分发映射无效');
        }
        $prefix = $name === 'type-project' ? 'templates/type-project' : 'plugin/' . $name;
        if (($package['prefix'] ?? '') !== $prefix || ($package['composer-name'] ?? '') !== 'zoujingli/' . $name) {
            throw new RuntimeException('组件源码位置或包名不匹配：' . $name);
        }
        $composer = json_decode(file_get_contents($root . '/' . $prefix . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $description = trim((string) ($composer['description'] ?? ''));
        if (($composer['name'] ?? '') !== $package['composer-name'] || ($composer['license'] ?? '') !== 'Apache-2.0'
            || $description === '' || str_contains($description, "\n") || str_contains($description, "\r")) {
            throw new RuntimeException('组件包名、Apache-2.0 或简介不完整：' . $name);
        }
        $repository = $package['repository'];
        $secret = strtoupper(str_replace('-', '_', $name)) . '_DEPLOY_KEY';
        [$status, $value, $error] = Process::run(['gh', 'api', 'repos/' . $repository], $root);
        if ($status !== 0) {
            if (!str_contains($error, '404')) {
                throw new RuntimeException('无法核对分发仓库：' . $repository);
            }
            Process::output(['gh', 'repo', 'create', $repository, '--public', '--description', $description], $root);
            $value = Process::output(['gh', 'api', 'repos/' . $repository], $root);
        }
        $actual = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (($actual['full_name'] ?? '') !== $repository || ($actual['private'] ?? null) !== false
            || ($actual['visibility'] ?? '') !== 'public' || ($actual['archived'] ?? true) !== false) {
            throw new RuntimeException('目标不是可写的公开仓库：' . $repository);
        }
        if (($actual['description'] ?? '') !== $description) {
            Process::output(['gh', 'repo', 'edit', $repository, '--description', $description], $root);
        }
        if (in_array($secret, $known, true)) {
            echo $repository . "：保留已有公开目标与专用凭据。\n";
            continue;
        }
        $temporary = Process::output(['mktemp', '-d'], $root);
        $key = $temporary . '/deploy-key';
        try {
            Process::output(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'type-app:' . $name . ':distribution', '-f', $key], $root);
            Process::output(['gh', 'repo', 'deploy-key', 'add', $key . '.pub', '--repo', $repository, '--allow-write', '--title', 'type-app Actions 专用分发'], $root);
            provisionSecret($root, $secret, $key);
            echo $repository . "：公开仓库与独立分发凭据已配置。\n";
        } finally {
            foreach ([$key, $key . '.pub'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($temporary);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, '配置分发目标失败：' . $error->getMessage() . "\n");
    exit(1);
}
