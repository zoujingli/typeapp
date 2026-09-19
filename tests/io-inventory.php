<?php

declare(strict_types=1);

require_once __DIR__ . '/support.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use Type\Build\ArtifactManifest;
use Type\Build\BuildIdentity;
use Type\Build\NativeLibraryProbe;
use Type\Runtime\Arguments;

/**
 * 审计既有构建报告的完整输入，不重新实现生产依赖选择或自动加载规则。
 * 结果列出所有显式调用；动态分派、隐式析构及原生实现须人工复核，不能据此宣称零遗漏。
 */
final class IoInventory
{
    private Standard $printer;
    private array $calls = [];
    private array $definitions = [];

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /** @return array{calls: list<array>, definitions: list<array>} 不执行被审计源码。 */
    public function inspect(string $source, string $file): array
    {
        $this->calls = [];
        $this->definitions = [];
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        expect($nodes !== null, '无法解析生产输入：' . $file);
        $nodes = (new NodeTraverser(new NameResolver()))->traverse($nodes);
        foreach ($nodes as $node) {
            $this->visit($node, $file, '<declarations>', '', '');
        }
        return ['calls' => $this->calls, 'definitions' => $this->definitions];
    }

    private function visit(Node $node, string $file, string $owner, string $class, string $namespace): void
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $namespace = $node->name?->toString() ?? '';
        }
        if ($node instanceof Node\Stmt\ClassLike) {
            $class = isset($node->namespacedName) ? $node->namespacedName->toString() : '<anonymous@' . $node->getStartLine() . '>';
        }
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            $owner = $node instanceof Node\Stmt\ClassMethod ? $class . '::' . $node->name->toString() : $node->namespacedName->toString();
            $this->definitions[] = ['file' => $file, 'line' => $node->getStartLine(), 'symbol' => $owner,
                'body' => $node->stmts !== null, 'implicit_entry' => str_starts_with($node->name->toString(), '__')];
        } elseif ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $owner .= '/closure@' . $node->getStartLine();
        }
        $kind = '';
        $target = '';
        $receiver = '';
        $resolution = 'requires-review';
        if ($node instanceof Node\Expr\FuncCall) {
            $kind = 'function';
            $target = $node->name instanceof Node\Name ? $node->name->toString() : $this->printer->prettyPrintExpr($node->name);
            $resolution = $node->name instanceof Node\Name\FullyQualified ? 'named' : 'namespace-fallback-or-dynamic';
        } elseif ($node instanceof Node\Expr\StaticCall) {
            $kind = 'static';
            $receiver = $node->class instanceof Node\Name ? $node->class->toString() : $this->printer->prettyPrintExpr($node->class);
            $target = $node->name instanceof Node\Identifier ? $node->name->toString() : $this->printer->prettyPrintExpr($node->name);
        } elseif ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\NullsafeMethodCall) {
            $kind = $node instanceof Node\Expr\NullsafeMethodCall ? 'nullsafe-method' : 'method';
            $receiver = $this->printer->prettyPrintExpr($node->var);
            $target = $node->name instanceof Node\Identifier ? $node->name->toString() : $this->printer->prettyPrintExpr($node->name);
        } elseif ($node instanceof Node\Expr\New_) {
            $kind = 'constructor';
            $target = $node->class instanceof Node\Name ? $node->class->toString() : '<dynamic-or-anonymous>';
        } elseif ($node instanceof Node\Stmt\Echo_ || $node instanceof Node\Expr\Print_) {
            $kind = 'output';
            $target = $node instanceof Node\Stmt\Echo_ ? 'echo' : 'print';
        } elseif ($node instanceof Node\Expr\Include_ || $node instanceof Node\Expr\Eval_ || $node instanceof Node\Expr\ShellExec) {
            $kind = 'dynamic-execution';
            $target = $node->getType();
        }
        if ($kind !== '') {
            $this->calls[] = ['file' => $file, 'line' => $node->getStartLine(), 'owner' => $owner, 'namespace' => $namespace,
                'kind' => $kind, 'receiver' => $receiver, 'target' => $target, 'resolution' => $resolution,
                'io_candidate' => self::category($kind, $target, $receiver),
                'phase' => 'requires-call-chain-review'];
        }
        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            foreach (is_array($value) ? $value : [$value] as $child) {
                if ($child instanceof Node) {
                    $this->visit($child, $file, $owner, $class, $namespace);
                }
            }
        }
    }

    /** 分类只供筛选，命名空间遮蔽、接收者类型及回调必须沿调用链核对。 */
    private static function category(string $kind, string $target, string $receiver): ?string
    {
        $name = strtolower($target);
        if ($kind === 'output' || $kind === 'dynamic-execution') {
            return $kind;
        }
        if ($kind === 'function') {
            foreach ([
                'file' => ['fopen', 'fclose', 'fread', 'fwrite', 'fputs', 'fgets', 'fgetcsv', 'fputcsv', 'fflush', 'fsync', 'fdatasync', 'flock', 'fseek', 'ftell', 'fstat', 'ftruncate', 'feof', 'rewind', 'readfile', 'file', 'file_get_contents', 'file_put_contents', 'copy', 'rename', 'unlink', 'mkdir', 'rmdir', 'opendir', 'readdir', 'closedir', 'scandir', 'glob', 'stat', 'lstat', 'filesize', 'filemtime', 'is_file', 'is_dir', 'is_readable', 'is_writable', 'file_exists', 'realpath', 'readlink', 'chmod', 'chown', 'touch', 'disk_free_space', 'hash_file', 'hash_update_file', 'parse_ini_file', 'tempnam', 'tmpfile'],
                'process' => ['proc_open', 'proc_close', 'proc_terminate', 'proc_get_status', 'popen', 'pclose', 'exec', 'shell_exec', 'system', 'passthru', 'pcntl_fork', 'pcntl_wait', 'pcntl_waitpid', 'posix_kill'],
                'wait' => ['sleep', 'usleep', 'time_nanosleep', 'time_sleep_until'],
                'network' => ['gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_get_record', 'fsockopen', 'pfsockopen', 'header', 'setcookie', 'flush'],
                'log' => ['error_log', 'syslog', 'openlog', 'closelog'],
            ] as $category => $names) {
                if (in_array($name, $names, true)) {
                    return $category;
                }
            }
            foreach (['stream_' => 'stream', 'socket_' => 'network', 'curl_' => 'http', 'pg_' => 'database', 'mysqli_' => 'database'] as $prefix => $category) {
                if (str_starts_with($name, $prefix)) {
                    return $category;
                }
            }
        }
        if ($kind === 'constructor' && ($name === 'pdo' || $name === 'redis' || str_starts_with($name, 'swoole\\') || str_starts_with($name, 'splfile'))) {
            return 'extension-resource';
        }
        if ($kind === 'static' && str_starts_with(strtolower($receiver), 'swoole\\')) {
            return 'swoole';
        }
        if (in_array($kind, ['method', 'nullsafe-method', 'static'], true) && in_array($name, [
            'connect', 'query', 'prepare', 'execute', 'fetch', 'fetchall', 'closecursor', 'begintransaction', 'commit', 'rollback',
            'openstream', 'fetchstream', 'closestream', 'read', 'write', 'send', 'recv', 'receive', 'publish', 'reserve', 'borrow',
            'wait', 'flush', 'close', 'reset', 'stop', 'initialize', 'accept', 'transaction', 'log',
            // 原生 HTTP/WS 和 Socket 的发送、数据报、TLS 及关闭入口也须沿实际接收者复核。
            'bind', 'listen', 'start', 'shutdown', 'end', 'sendfile', 'upgrade', 'push', 'ping', 'disconnect',
            'recvfrom', 'sendto', 'recvall', 'sendall', 'recvpacket', 'recvline', 'recvwithbuffer', 'peek',
            'readvector', 'readvectorall', 'writevector', 'writevectorall', 'sslhandshake', 'cancel',
            'get', 'post', 'download',
        ], true)) {
            return 'method-needs-receiver-resolution';
        }
        return null;
    }
}

// 与既有测试控制器一致，允许只加载审计器；直接执行仍要求完整构建与产物身份。
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$arguments = new Arguments($argv, ['report', 'source-root', 'restored-root', 'output'], []);
$root = realpath($arguments->text('source-root', dirname(__DIR__)));
expect(is_string($root) && is_dir($root), '源码根不存在');
$reportPath = realpath($arguments->text('report', ''));
$restore = rtrim($arguments->text('restored-root', ''), '/\\');
$output = $arguments->text('output', '');
expect(is_string($reportPath) && is_file($reportPath) && $output !== '', '需要 --report 构建报告、--output 新报告；相对路径以当前工作目录为准');
expect(!file_exists($output) && !is_link($output) && is_dir(dirname($output)), '输出必须是已有目录中的新文件');
$report = json_decode(file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
BuildIdentity::assertValid($report['identity']);
$description = $report['identity']['description'];
$oldRoot = rtrim($description['facts']['workspace'], '/\\');
$inputs = $description['inputs'];
$nativeProbe = (new NativeLibraryProbe())->sources();
$inventory = new IoInventory();
$files = [];
$calls = [];
$definitions = [];
$groups = [];
foreach (['sources', 'original-sources', 'declarations', 'locks'] as $group) {
    foreach ($inputs[$group] as $path => $identity) {
        expect(str_starts_with($path, $oldRoot . '/'), '构建输入不在声明的项目根内');
        $relative = substr($path, strlen($oldRoot) + 1);
        expect(!in_array('..', explode('/', $relative), true), '构建输入含路径跳转');
        $actual = $root . '/' . $relative;
        $origin = 'working-tree';
        if (!is_file($actual) && $restore !== '' && is_file($restore . '/' . $relative)) {
            $actual = $restore . '/' . $relative;
            $origin = 'restored';
        }
        if (is_file($actual)) {
            $source = file_get_contents($actual);
        } elseif ($group === 'sources' && str_starts_with($relative, 'build/') && isset($nativeProbe[basename($relative)])) {
            $source = $nativeProbe[basename($relative)];
            // 生成器的writeText保留精确字节；只接受与旧构建记录逐字节相同的重建输入。
            $origin = 'regenerated-and-hash-verified';
        } else {
            throw new RuntimeException('缺少构建输入：' . $relative);
        }
        expect(hash('sha256', $source) === $identity['sha256'] && strlen($source) === $identity['bytes'], '构建输入已经变化：' . $relative);
        $groups[$group][$relative] = $identity + ['origin' => $origin];
        if ($group !== 'sources') {
            continue;
        }
        $php = pathinfo($relative, PATHINFO_EXTENSION) === 'php';
        $files[$relative] = $identity + ['origin' => $origin, 'audit' => $php ? 'php-ast' : 'native-review-required'];
        if ($php) {
            $scan = $inventory->inspect($source, $relative);
            array_push($calls, ...$scan['calls']);
            array_push($definitions, ...$scan['definitions']);
        }
    }
}
$binary = substr($reportPath, -11) === '.build.json' ? substr($reportPath, 0, -11) : '';
expect(is_file($binary) && hash_file('sha256', $binary) === $report['sha256'], '构建报告与产物摘要不一致');
$manifestReader = new ArtifactManifest();
$manifest = $manifestReader->read($binary, $report['identity']['id'], $report['sha256']);
expect(BuildIdentity::digest($manifest) === BuildIdentity::digest($report['manifest']), '内嵌清单与构建报告不一致');
// 自身身份代码在identity形成之后生成，不能假装它已在inputs.sources中。
// 从真实产物清单与当时同字节生成器重建供审查的代码，不称其为归档原文。
$generatorRelative = 'plugin/type-build/src/ArtifactManifest.php';
$generator = dirname(__DIR__) . '/' . $generatorRelative;
$generatorIdentity = $inputs['tooling'][$oldRoot . '/' . $generatorRelative] ?? null;
expect(is_array($generatorIdentity) && hash_file('sha256', $generator) === $generatorIdentity['sha256'], '身份生成器已经变化，不能重建历史生成调用');
foreach (['manifest-protocol', 'binary-format', 'elf-sha256', 'elf-bytes'] as $sealField) {
    unset($manifest[$sealField]);
}
$generatedIdentity = $manifestReader->accessor($manifest);
$derivedFile = '<derived>/generated-build-identity.php';
$files[$derivedFile] = ['sha256' => hash('sha256', $generatedIdentity), 'bytes' => strlen($generatedIdentity),
    'origin' => 'embedded-manifest-and-verified-generator', 'audit' => 'php-ast', 'original_source_bytes_verified' => false];
$derivedScan = $inventory->inspect($generatedIdentity, $derivedFile);
array_push($calls, ...$derivedScan['calls']);
array_push($definitions, ...$derivedScan['definitions']);
$native = [];
foreach ($description['facts']['native']['native-libraries'] as $library) {
    $native[] = ['name' => $library['name'], 'sha256' => $library['sha256'],
        'current_match' => is_file($library['path']) && hash_file('sha256', $library['path']) === $library['sha256']];
}
$categories = [];
foreach ($calls as $call) {
    $category = $call['io_candidate'] ?? 'unclassified-call';
    $categories[$category] = ($categories[$category] ?? 0) + 1;
}
ksort($categories);
$result = ['protocol' => 2, 'status' => 'inventory-requires-call-chain-review', 'path_base' => 'project-root',
    'build_id' => $report['identity']['id'], 'build_report_sha256' => hash_file('sha256', $reportPath), 'artifact_sha256' => $report['sha256'],
    'platform' => ['php' => $report['php'], 'zts' => $report['zts'], 'architecture' => $report['architecture']],
    'production_packages' => $description['facts']['production-packages'], 'input_groups' => $groups, 'files' => $files,
    'native_libraries' => $native, 'summary' => ['files' => count($files), 'definitions' => count($definitions), 'calls' => count($calls), 'categories' => $categories],
    'limitations' => ['不是运行耗时或调用图完备证明；未分类调用不能视为纯计算。',
        '生命周期阶段需要从启动、请求/任务及收尾根入口逐链核对，同一方法可能属于多个阶段。',
        '方法接收者、接口分派、命名空间回退、动态回调、属性hook、魔术访问及隐式析构仍须核对。',
        '原生输入和外部扩展单独审查；没有把PHP声明stub当作原生I/O实现。',
        '身份访问器是从内嵌清单重建的审查副本；不宣称其字节顺序等于历史生成原文。',
        '原生清单数据封装由ArtifactManifest生成器复核，未将其列成普通PHP调用。',
        '仅核对列明的生产、原始、声明、锁及运行库；不宣称全部历史工具源码仍在本机。'],
    'definitions' => $definitions, 'calls' => $calls];
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
expect(file_put_contents($output, $json, LOCK_EX) === strlen($json), '无法保存I/O调用清单');
echo json_encode($result['summary'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
