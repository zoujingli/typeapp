<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 从受信发布包生成系统服务配置；不安装服务、不读部署秘密、不修改运行数据。 */
final class ServiceDefinition
{
    /**
     * 路径指向目标机器的最终部署位置，生成机器不必存在这些目标路径。
     *
     * @param array{name:string, 'runtime-directory':string, user?:string, scope?:string, 'release-directory'?:string, arguments?:list<string>, 'stop-seconds'?:int, 'restart-seconds'?:int, 'windows-wrapper'?:array{path:string, sha256:string}} $settings 只接受公开部署参数；秘密由运行根的.env提供。
     * @return array{directory:string, manager:string, descriptor:string, 'manifest-sha256':string}
     * @throws RuntimeException 发布摘要、参数或包装器不可信，目录重叠、目标已存在或无法完整写入。
     */
    public function create(string $releaseDirectory, string $destination, string $releaseSha256, array $settings): array
    {
        $releaseDirectory = BuildPlatform::resolve($releaseDirectory);
        $release = (new NativePackage())->verify($releaseDirectory, $releaseSha256);
        $family = $release['runtime']['os'];
        $platform = new BuildPlatform($family);
        if (array_diff(array_keys($settings), ['name', 'runtime-directory', 'user', 'scope', 'release-directory', 'arguments', 'stop-seconds', 'restart-seconds', 'windows-wrapper']) !== []) {
            throw new RuntimeException('服务声明包含未知字段；秘密不能作为服务生成参数');
        }
        $name = $settings['name'] ?? null;
        if (!is_string($name) || preg_match('/^[A-Za-z][A-Za-z0-9]{0,63}$/D', $name) !== 1) {
            throw new RuntimeException('跨平台服务名称必须以字母开头且只包含字母数字，最多64字符');
        }
        $target = $this->path($settings['release-directory'] ?? $releaseDirectory, $platform);
        $runtime = $this->path($settings['runtime-directory'] ?? null, $platform);
        if ($this->overlaps($target, $runtime, $family)) {
            throw new RuntimeException('运行数据根与不可变发布目录必须分离');
        }
        $arguments = $settings['arguments'] ?? ['serve'];
        if (!is_array($arguments) || !array_is_list($arguments) || $arguments === [] || count($arguments) > 32) {
            throw new RuntimeException('服务参数必须为1到32项的公开参数数组');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || strlen($argument) > 512 || preg_match('//u', $argument) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $argument) || ($family === 'Windows' && str_contains($argument, '%'))) {
                throw new RuntimeException('服务参数无效；Windows不接受环境变量插值');
            }
        }
        $stop = $settings['stop-seconds'] ?? 30;
        $restart = $settings['restart-seconds'] ?? 10;
        if (!is_int($stop) || $stop < 1 || $stop > 300 || !is_int($restart) || $restart < 1 || $restart > 3600) {
            throw new RuntimeException('服务停止预算须为1到300秒，重启间隔须为1到3600秒');
        }
        $user = $settings['user'] ?? '';
        $scope = $settings['scope'] ?? ($family === 'Windows' ? 'system' : 'user');
        if (!in_array($scope, ['user', 'system'], true) || ($family === 'Windows' && $scope !== 'system')) {
            throw new RuntimeException('服务作用域只接受user/system；Windows只支持system');
        }
        if ($family !== 'Windows' && (!is_string($user) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]{0,63}$/D', $user) !== 1 || strtolower($user) === 'root')) {
            throw new RuntimeException('Unix服务必须显式指定非root运行账号');
        }
        if ($family === 'Windows' && isset($settings['user'])) {
            throw new RuntimeException('Windows配置固定使用LocalService，不接受账号密码或默认LocalSystem');
        }
        if ($family !== 'Windows' && isset($settings['windows-wrapper'])) {
            throw new RuntimeException('非Windows目标不接受Windows包装器');
        }
        $environment = ['APP_BASE_PATH' => $runtime, 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'TYPE_APP_RELEASE_SHA256' => $releaseSha256];
        if ($family !== 'Windows') {
            $environment['PATH'] = '/usr/bin:/bin:/usr/sbin:/sbin';
        }
        $wrapper = null;
        if ($family === 'Darwin') {
            $manager = 'launchd';
            $filename = $name . '.plist';
            $contents = $this->launchd($name, $target, $runtime, $user, $arguments, $environment, $stop, $restart);
        } elseif ($family === 'Linux') {
            $manager = 'systemd';
            $filename = $name . '.service';
            $contents = $this->systemd($name, $target, $user, $arguments, $environment, $stop, $restart, $scope);
        } else {
            $wrapper = $settings['windows-wrapper'] ?? null;
            if (!is_array($wrapper) || array_keys($wrapper) === [] || array_diff(array_keys($wrapper), ['path', 'sha256']) !== []
                || !is_string($wrapper['path'] ?? null) || !is_string($wrapper['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $wrapper['sha256']) !== 1) {
                throw new RuntimeException('Windows服务需要显式提供WinSW 2.12.0包装器及外部受信SHA256');
            }
            $wrapper['path'] = BuildPlatform::resolve($wrapper['path']);
            BuildLock::path($wrapper['path']);
            if (!is_file($wrapper['path']) || filesize($wrapper['path']) > 134217728 || BuildPlatform::format($wrapper['path']) !== 'PE'
                || !hash_equals($wrapper['sha256'], (string) hash_file('sha256', $wrapper['path']))) {
                throw new RuntimeException('Windows服务包装器格式或受信摘要不一致');
            }
            $system = $this->path((string) getenv('SystemRoot'), $platform);
            $environment += ['TYPE_APP_RUNTIME_ROOT' => $target, 'PHPRC' => $target . '/runtime/php.ini',
                'PHP_INI_SCAN_DIR' => $target . '/runtime/empty', 'PHP_HOME' => '', 'PHPX_HOME' => '',
                'PATH' => $target . '/bin;' . $system . '/System32'];
            $manager = 'winsw-2.12.0';
            $filename = $name . '.xml';
            $contents = $this->windows($name, $target, $runtime, $arguments, $environment, $stop, $restart);
        }
        $parent = BuildPlatform::resolve(dirname($destination));
        $leaf = basename($destination);
        if ($leaf === '' || $leaf === '.' || $leaf === '..' || preg_match('/[\x00-\x1f\x7f]/', $leaf)) {
            throw new RuntimeException('服务输出目录名称无效');
        }
        $destination = $parent . '/' . $leaf;
        BuildLock::path($destination);
        if ($this->overlaps($destination, $releaseDirectory, $family) || $this->overlaps($destination, $target, $family) || $this->overlaps($destination, $runtime, $family)) {
            throw new RuntimeException('服务配置、运行数据与不可变发布目录必须分离');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('服务输出目录已存在，不能覆盖已安装配置');
        }
        $stage = $destination . '.building-' . bin2hex(random_bytes(6));
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('无法创建服务配置暂存目录');
        }
        try {
            $platform->privateCache($stage, true);
            $files = [$filename => $contents, 'SERVICE.md' => $this->instructions($manager, $name, $target, $runtime, $releaseSha256, $scope)];
            $hashes = [];
            foreach ($files as $file => $content) {
                if (file_put_contents($stage . '/' . $file, $content, LOCK_EX) !== strlen($content)) {
                    throw new RuntimeException('服务配置写入不完整');
                }
                chmod($stage . '/' . $file, 0600);
                $hashes[$file] = hash('sha256', $content);
            }
            if ($wrapper !== null) {
                $wrapperFile = $name . '.exe';
                if (!copy($wrapper['path'], $stage . '/' . $wrapperFile) || hash_file('sha256', $stage . '/' . $wrapperFile) !== $wrapper['sha256']) {
                    throw new RuntimeException('Windows包装器复制中发生变化');
                }
                $hashes[$wrapperFile] = $wrapper['sha256'];
            }
            $record = ['protocol' => 1, 'manager' => $manager, 'platform' => $family, 'scope' => $scope, 'name' => $name,
                'release-directory' => $target, 'runtime-directory' => $runtime, 'release-sha256' => $releaseSha256,
                'build-id' => $release['artifact']['build-id'], 'user' => $family === 'Windows' ? 'NT AUTHORITY\\LocalService' : $user,
                'descriptor' => $filename, 'files' => $hashes, 'installed' => false, 'runtime-verified' => false];
            $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (file_put_contents($stage . '/service.json', $json, LOCK_EX) !== strlen($json)) {
                throw new RuntimeException('无法写入服务生成记录');
            }
            chmod($stage . '/service.json', 0600);
            if (!rename($stage, $destination)) {
                throw new RuntimeException('无法原子发布服务配置');
            }
            return ['directory' => $destination, 'manager' => $manager, 'descriptor' => $destination . '/' . $filename, 'manifest-sha256' => hash('sha256', $json)];
        } finally {
            if (is_dir($stage)) {
                foreach (scandir($stage) as $file) {
                    if ($file !== '.' && $file !== '..') {
                        unlink($stage . '/' . $file);
                    }
                }
                rmdir($stage);
            }
        }
    }

    private function path(mixed $value, BuildPlatform $platform): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $value)
            || !$platform->absolute($value) || strlen($value) > 2048) {
            throw new RuntimeException('服务路径必须是有效的目标平台绝对路径');
        }
        $path = $platform->family() === 'Windows' ? str_replace('\\', '/', $value) : $value;
        $path = rtrim($path, '/');
        if ($path === '' || trim($path) !== $path || ($platform->family() === 'Linux' && str_ends_with($path, '\\'))
            || preg_match('~^[A-Za-z]:$~D', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path) || str_contains($path, '//')
            || ($platform->family() === 'Windows' && (str_contains($path, '%') || str_contains($path, ';')))) {
            throw new RuntimeException('服务路径不能为文件系统根、跳转或包含插值');
        }
        return $path;
    }

    private function overlaps(string $left, string $right, string $family): bool
    {
        if ($family === 'Windows') {
            $left = strtolower(str_replace('\\', '/', $left));
            $right = strtolower(str_replace('\\', '/', $right));
        }
        return $left === $right || str_starts_with($left, $right . '/') || str_starts_with($right, $left . '/');
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function launchd(string $name, string $release, string $runtime, string $user, array $arguments, array $environment, int $stop, int $restart): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<plist version=\"1.0\"><dict>\n";
        foreach (['Label' => $name, 'UserName' => $user, 'WorkingDirectory' => $release,
            'StandardOutPath' => $runtime . '/' . $name . '.stdout.log', 'StandardErrorPath' => $runtime . '/' . $name . '.stderr.log'] as $key => $value) {
            $xml .= '<key>' . $key . '</key><string>' . $this->xml($value) . "</string>\n";
        }
        $xml .= "<key>ProgramArguments</key><array>\n";
        foreach ([$release . '/run', ...$arguments] as $argument) {
            $xml .= '<string>' . $this->xml($argument) . "</string>\n";
        }
        $xml .= "</array><key>EnvironmentVariables</key><dict>\n";
        foreach ($environment as $key => $value) {
            $xml .= '<key>' . $key . '</key><string>' . $this->xml($value) . "</string>\n";
        }
        return $xml . "</dict><key>RunAtLoad</key><true/><key>KeepAlive</key><dict><key>SuccessfulExit</key><false/></dict>\n"
            . '<key>ExitTimeOut</key><integer>' . $stop . '</integer><key>ThrottleInterval</key><integer>' . $restart . "</integer>\n"
            . "<key>Umask</key><integer>63</integer></dict></plist>\n";
    }

    private function systemdQuote(string $value): string
    {
        $value = str_replace(['\\', '"', '%'], ['\\\\', '\\"', '%%'], $value);
        return '"' . $value . '"';
    }

    private function systemd(string $name, string $release, string $user, array $arguments, array $environment, int $stop, int $restart, string $scope): string
    {
        $command = array_map(fn (string $argument): string => $this->systemdQuote($argument), [$release . '/run', ...$arguments]);
        $unit = "[Unit]\nDescription=Type native application " . $name . "\nConditionUser=" . ($scope === 'user' ? $user : 'root')
            . "\nStartLimitIntervalSec=300\nStartLimitBurst=5\n\n[Service]\nType=exec" . ($scope === 'system' ? "\nUser=" . $user : '')
            // WorkingDirectory是原始路径字段，不采用ExecStart/Environment的词法去引号规则。
            . "\nWorkingDirectory=" . str_replace('%', '%%', $release) . "\nExecStart=:" . implode(' ', $command) . "\n";
        foreach ($environment as $key => $value) {
            $unit .= 'Environment=' . $this->systemdQuote($key . '=' . $value) . "\n";
        }
        return $unit . 'Restart=on-failure' . "\nRestartSec=" . $restart . "\nTimeoutStopSec=" . $stop
            . "\nKillSignal=SIGTERM\nKillMode=control-group\nSendSIGKILL=yes\nUMask=0077\nNoNewPrivileges=yes\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy="
            . ($scope === 'user' ? 'default.target' : 'multi-user.target') . "\n";
    }

    /** WinSW直接创建应用进程；不通过cmd运行，以保持控制台停止事件的归属。 */
    private function windows(string $name, string $release, string $runtime, array $arguments, array $environment, int $stop, int $restart): string
    {
        $encoded = [];
        foreach ($arguments as $argument) {
            // CommandLineToArgvW规则：引号前和结束引号前的反斜线必须加倍。
            $quoted = '"';
            $slashes = 0;
            for ($index = 0; $index < strlen($argument); $index++) {
                $character = $argument[$index];
                if ($character === '\\') {
                    $slashes++;
                    continue;
                }
                $quoted .= str_repeat('\\', $character === '"' ? $slashes * 2 + 1 : $slashes) . $character;
                $slashes = 0;
            }
            $encoded[] = $quoted . str_repeat('\\', $slashes * 2) . '"';
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<service>\n";
        foreach (['id' => $name, 'name' => $name, 'executable' => $release . '/bin/app.exe', 'arguments' => implode(' ', $encoded),
            'workingdirectory' => $release, 'logpath' => $runtime, 'stoptimeout' => $stop . 'sec', 'startmode' => 'Manual'] as $key => $value) {
            $xml .= '<' . $key . '>' . $this->xml($value) . '</' . $key . ">\n";
        }
        foreach ($environment as $key => $value) {
            $xml .= '<env name="' . $key . '" value="' . $this->xml($value) . '"/>' . "\n";
        }
        return $xml . '<onfailure action="restart" delay="' . $restart . 'sec"/><onfailure action="restart" delay="' . $restart
            . "sec\"/><onfailure action=\"none\"/><resetfailure>1 hour</resetfailure>\n"
            . "<serviceaccount><domain>NT AUTHORITY</domain><user>LocalService</user></serviceaccount>\n<log mode=\"roll\"/></service>\n";
    }

    private function instructions(string $manager, string $name, string $release, string $runtime, string $digest, string $scope): string
    {
        $text = "# 原生服务配置\n\n生成不等于安装或运行通过。本目录不包含PHP工具；release.json受信摘要为 `" . $digest . "`。\n\n"
            . '发布最终位置：`' . $release . '`。数据/配置最终位置：`' . $runtime . "`。路径改变后重新生成配置，不复用错误路径。\n\n"
            . "先为显式运行账号准备私有数据目录/.env和只读发布权限；外部.env不复制到发布或服务包。用发布启动器显式执行verify-runtime及迁移，再启动服务。服务状态不是业务就绪；标准应用另检查/healthz和/readyz。\n\n";
        if ($manager === 'launchd') {
            $domain = $scope === 'user' ? 'gui/<UID>' : 'system';
            return $text . '使用声明的launchd域：`launchctl bootstrap ' . $domain . ' <配置绝对路径>`，`launchctl print ' . $domain . '/' . $name
                . '`，停止并卸载用 `launchctl bootout ' . $domain . '/' . $name . "`。不自动安装登录项。系统LaunchDaemon由管理员核对非root账号、所有权、目录权限和服务策略后安装。\n\n"
                . "异常退出按ThrottleInterval重启；正常退出不重启。bootout发SIGTERM，超过ExitTimeOut会强杀，不能把强杀当作正常排空。日志在数据根，另行配置保留/轮转策略；配置不包含后台自更新或自动迁移。\n";
        }
        if ($manager === 'systemd') {
            $control = $scope === 'user' ? 'systemctl --user' : 'systemctl';
            $journal = $scope === 'user' ? 'journalctl --user' : 'journalctl';
            return $text . '需要支持Type=exec和ConditionUser的systemd（至少244）。配置作用域：' . $scope . '。使用 `' . $control . ' link <unit绝对路径>`、`' . $control . ' daemon-reload`、`' . $control . ' start ' . $name
                . '.service`、`' . $control . ' status ' . $name . '.service`、`' . $journal . ' -u ' . $name . '.service`。停止：`' . $control . ' stop ' . $name
                . ".service`；卸载本次链接后再daemon-reload。user作用域不重设进程身份，用ConditionUser限制管理器用户；system作用域由管理员加载并切到显式非root账号，不可混用。生成器不enable或开启linger；需要自启动时由操作者明确选择。\n\n"
                . "五分钟内最多启动五次，超限需排障后reset-failed。停止向整组发送SIGTERM，超预算才SIGKILL；确认应用退出码和日志，不能把强杀视为正常停止。\n";
        }
        return $text . "附带的包装器必须来自外部受信WinSW 2.12.0发行源；摘要匹配不是版本/签名或来源真实性的独立证明。管理员先核对其签名/版本和service.json中摘要，并为LocalService授予发布目录读执行、数据根修改权限，不能给发布目录写权限。\n\n运行同目录 `" . $name . '.exe install`、`' . $name . '.exe start`、`' . $name . '.exe status`、`' . $name . '.exe stop`、`' . $name . ".exe uninstall`。默认手动启动，不自动改开机启动。\n\n"
            . "WinSW直接启动原生exe并先发送控制台Ctrl+C，超过停止预算会强杀；必须在Windows原生环境验证正常停止。崩溃最多重启两次后停止，日志由WinSW roll策略保留。生成器不使用LocalSystem，不写账号密码，也不自动下载或执行包装器。\n";
    }
}
