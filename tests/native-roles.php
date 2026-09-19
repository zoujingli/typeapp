<?php

declare(strict_types=1);

// 构建机上的验收控制器；不复制进任何应用运行镜像。
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Testing\Assert;
use Type\Testing\Process;

function roleProcess(array $command, float $seconds = 15): string
{
    $process = new Process($command, dirname(__DIR__), getenv());
    try {
        $result = $process->wait($seconds);
        Assert::true($result->successful(), '独立角色子进程失败：' . $result->stdout . $result->stderr);
        return $result->stdout;
    } finally {
        $process->stop();
    }
}

function roleWrite(string $file, array $value): void
{
    $text = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    Assert::same(strlen($text), file_put_contents($file, $text), '无法保存独立角色验收记录');
}

function roleJson(string $file): array
{
    $value = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    Assert::true(is_array($value), '独立角色记录不是对象');
    return $value;
}

/** 每次 create/start 都建立新进程，生产载体没有解释器和源码挂载。 */
function roleRun(string $name, array $arguments, string $image, array $settings, string $network, string $volume, string $label, ?string $pauseRedis = null): array
{
    $command = ['docker', 'create', '--name', $name, '--pull=never', '--label', $label, '--network', $network,
        '--read-only', '--cap-drop=ALL', '--security-opt=no-new-privileges', '--workdir', '/app',
        '--tmpfs', '/tmp:rw,nosuid,nodev,size=32m', '--mount', 'type=volume,source=' . $volume . ',target=/var/lib/type-roles'];
    foreach ($settings as $key => $value) {
        array_push($command, '--env', $key . '=' . $value);
    }
    array_push($command, $image, ...$arguments);
    $identifier = trim(roleProcess($command));
    try {
        // 只向本轮 Redis 请求写暂停；OCI 冷启动可能消耗该窗口，不把请求时长冒充实际发布等待。
        if ($pauseRedis !== null) {
            Assert::same("OK\n", roleProcess(['docker', 'exec', $pauseRedis, 'redis-cli', 'CLIENT', 'PAUSE', '500', 'WRITE']), '无法注入本轮 Redis 写等待');
        }
        $process = new Process(['docker', 'start', '--attach', $identifier], dirname(__DIR__), getenv());
        try {
            $result = $process->wait(15);
        } finally {
            $process->stop();
        }
        Assert::true($result->successful() && $result->stderr === '', '无源码角色运行失败：' . $result->stdout . $result->stderr);
        $state = json_decode(roleProcess(['docker', 'inspect', $identifier]), true, 512, JSON_THROW_ON_ERROR)[0];
        Assert::same($image, $state['Image'], '独立角色镜像身份变化');
        Assert::same('/app/type-app', $state['Path'], '独立角色没有从原生 ELF 入口启动');
        Assert::same($arguments, $state['Args'], '独立角色启动参数不符');
        Assert::true(!$state['State']['Running'] && !$state['State']['OOMKilled'] && $state['State']['ExitCode'] === 0, '独立角色未正常退出');
        Assert::true($state['HostConfig']['ReadonlyRootfs'] && !$state['HostConfig']['Privileged'], '独立角色根目录或权限边界错误');
        Assert::same(['ALL'], $state['HostConfig']['CapDrop'], '独立角色没有清除 capabilities');
        Assert::true(in_array('no-new-privileges', $state['HostConfig']['SecurityOpt'], true), '独立角色没有限制新权限');
        Assert::same('', $state['HostConfig']['PidMode'], '独立角色不应共享宿主 PID');
        Assert::same(1, count($state['Mounts']), '独立角色出现额外挂载');
        Assert::true($state['Mounts'][0]['Type'] === 'volume' && $state['Mounts'][0]['Name'] === $volume
            && $state['Mounts'][0]['Destination'] === '/var/lib/type-roles', '独立角色挂载了源码或非本轮数据');
        return ['container-id' => $identifier, 'image' => $image, 'arguments' => $arguments, 'exit-code' => 0,
            'started-at' => $state['State']['StartedAt'], 'finished-at' => $state['State']['FinishedAt'], 'output' => $result->stdout,
            'network' => $network, 'root-filesystem' => 'readonly', 'source-mounts' => [], 'capabilities' => [], 'no-new-privileges' => true,
            'redis-write-pause-requested-milliseconds' => $pauseRedis === null ? 0 : 500];
    } finally {
        roleProcess(['docker', 'rm', '--force', $identifier]);
    }
}

function roleVerify(string $directory, string $identity, string $network, string $redis, string $volume, string $outboxImage, string $schedulerImage): void
{
    $root = dirname(__DIR__);
    $label = 'type-native-roles=' . $identity;
    Assert::true(preg_match('/^[a-f0-9]{16}$/D', $identity) === 1, '独立角色测试标识无效');
    Assert::same('type-native-roles-redis-' . $identity, $redis, '写等待只允许本轮专属 Redis');
    roleWrite($directory . '/report.json', ['protocol' => 1, 'status' => 'running', 'label' => $label]);
    $images = [];
    $builds = [];
    foreach (['outbox' => $outboxImage, 'scheduler' => $schedulerImage] as $role => $image) {
        $artifact = $root . '/build/' . $role . '/type-app';
        // 复用现有严格镜像检查：实际文件系统、原产物摘要、无 PHP/Composer/SDK 和精确原生库白名单。
        $imageDirectory = $directory . '/' . $role;
        Assert::true(mkdir($imageDirectory, 0700), '无法创建角色镜像记录目录');
        $images[$role] = json_decode(roleProcess([PHP_BINARY, $root . '/tests/template-deployment.php', 'image', $image, $artifact, $imageDirectory], 45), true, 512, JSON_THROW_ON_ERROR);
        $build = roleJson($artifact . '.build.json');
        $builds[$role] = ['build-id' => $build['build-id'], 'artifact-sha256' => $build['sha256'], 'production-packages' => $build['production-packages']];
    }
    $settings = ['TYPE_SQLITE_FILE' => '/var/lib/type-roles/outbox.sqlite', 'TYPE_REDIS_HOST' => $redis,
        'TYPE_REDIS_PORT' => '6379', 'TYPE_OUTBOX_APPLICATION' => 'type_roles_' . $identity, 'TYPE_OUTBOX_DEPLOYMENT_CHECK' => '1'];
    $records = [];
    $records['database-command'] = roleRun('type-native-role-' . $identity . '-database', ['sqlite', 'setup'], $outboxImage, $settings, 'none', $volume, $label);
    Assert::same("业务与消息意图同事务提交通过。\n", $records['database-command']['output'], '数据库角色没有原子保存业务与消息意图');
    $records['outbox-relay'] = roleRun('type-native-role-' . $identity . '-relay', ['sqlite', 'relay'], $outboxImage, $settings, $network, $volume, $label, $redis);
    Assert::same("消息发布及 token 标记通过。\n", $records['outbox-relay']['output'], '独立 relay 没有发布原进程留下的意图');
    // 既有公开模式 consume-replay 的行为是消费一条消息；不改生产入口中的严格效果与凭据断言。
    $records['queue-worker'] = roleRun('type-native-role-' . $identity . '-worker', ['sqlite', 'consume-replay'], $outboxImage, $settings, $network, $volume, $label);
    Assert::same("重复投递幂等消费与保留凭据通过。\n", $records['queue-worker']['output'], '独立 worker 没有形成一次业务效果及消费凭据');
    $schedule = ['TYPE_SCHEDULER_STATE' => '/var/lib/type-roles/scheduler.json', 'TYPE_SCHEDULER_NOW' => '2026-09-09T12:00:00Z',
        'TYPE_SCHEDULER_SCENARIO' => 'normal', 'TYPE_REDIS_HOST' => '192.0.2.1'];
    $records['scheduler-help'] = roleRun('type-native-role-' . $identity . '-help', ['help'], $schedulerImage, $schedule, 'none', $volume, $label);
    Assert::true(str_contains($records['scheduler-help']['output'], '调度命令'), '独立调度帮助失败');
    $records['scheduler-once'] = roleRun('type-native-role-' . $identity . '-scheduler', ['once'], $schedulerImage, $schedule, 'none', $volume, $label);
    $first = json_decode($records['scheduler-once']['output'], true, 512, JSON_THROW_ON_ERROR);
    Assert::same(['succeeded', 'succeeded'], array_column($first, 'state'), '独立调度没有实际完成两个有限补跑任务');
    Assert::same('计划任务已完成', $first[1]['result']['message'], '独立调度任务没有业务结果');
    $schedule['TYPE_SCHEDULER_REVISION'] = 'next-native-role';
    $records['scheduler-restart'] = roleRun('type-native-role-' . $identity . '-restart', ['work', '2', '1'], $schedulerImage, $schedule, 'none', $volume, $label);
    Assert::same("[]\n[]\n", $records['scheduler-restart']['output'], '新的独立调度进程重复执行了同一计划');
    $records['scheduler-history'] = roleRun('type-native-role-' . $identity . '-history', ['history'], $schedulerImage, $schedule, 'none', $volume, $label);
    $history = json_decode($records['scheduler-history']['output'], true, 512, JSON_THROW_ON_ERROR);
    Assert::same($first[0]['occurrence_id'], $history[0]['occurrence_id'], '跨进程调度身份没有持久保留');
    Assert::same(strtotime('2026-09-09T12:00:00Z'), $history[1]['finished_at'], '跨进程调度完成记录错误');
    Assert::same(count($records), count(array_unique(array_column($records, 'container-id'))), '多个角色意外复用同一个进程容器');
    roleWrite($directory . '/report.json', ['protocol' => 1, 'status' => 'behavior-passed-awaiting-cleanup', 'label' => $label,
        'scope' => '独立数据库命令 → Outbox relay → queue worker；独立 scheduler 启动与状态恢复',
        'images' => $images, 'builds' => $builds, 'roles' => $records, 'business-effects' => ['outbox-effects' => 1, 'scheduled-completions' => 2],
        'outbox-positive-budget' => ['lease-milliseconds' => 5000, 'retention-seconds' => 60, 'fault-modes-unchanged' => true],
        'limitations' => ['本项补独立角色无源码部署，不重复三库或全部故障矩阵', '框架实现未修改，既有演练入口仅调整正向部署预算', '有界角色退出，不替代长期守护进程监督验收']]);
    echo "七个独立无源码进程的角色、业务效果与状态恢复通过，等待回收测试资源。\n";
}

try {
    $mode = $argv[1] ?? '';
    if ($mode === 'verify') {
        roleVerify(...array_slice($argv, 2));
    } elseif ($mode === 'complete') {
        $file = ($argv[2] ?? '') . '/report.json';
        $report = roleJson($file);
        Assert::same('behavior-passed-awaiting-cleanup', $report['status'], '角色行为没有通过，不能完成验收');
        foreach ([['docker', 'ps', '-a', '--quiet'], ['docker', 'network', 'ls', '--quiet'], ['docker', 'volume', 'ls', '--quiet'], ['docker', 'image', 'ls', '--quiet']] as $command) {
            Assert::same('', trim(roleProcess([...$command, '--filter', 'label=' . $report['label']])), '本轮独立角色测试资源仍未清理');
        }
        $report['status'] = 'passed';
        $report['test-resources'] = 'removed';
        roleWrite($file, $report);
        echo '独立角色无源码部署与资源回收全部通过：' . $file . "\n";
    } else {
        throw new InvalidArgumentException('用法：native-roles.php verify <目录> <随机标识> <网络> <Redis> <卷> <Outbox镜像> <调度镜像> | complete <目录>');
    }
} catch (Throwable $error) {
    fwrite(STDERR, '独立角色部署验收失败：' . $error->getMessage() . "\n");
    exit(1);
}
