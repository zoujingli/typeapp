<?php

declare(strict_types=1);

namespace Type\Build;

use PhpParser\ConstExprEvaluator;
use PhpParser\ConstExprEvaluationException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RuntimeException;

/** 将源码 Schema 声明与显式冻结结果绑定；正常编译只核验并嵌入已审查 SQL。 */
final class SchemaCompiler
{
    /** 显式准备一个独立 Schema 声明；已有不同快照不可覆盖，应使用新版本和新路径。 */
    public function prepare(string $source): array
    {
        $declarations = $this->declarations([BuildPlatform::resolve($source)]);
        if (count($declarations) !== 1) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：准备入口需要恰好一个独立 Schema 声明');
        }
        $declaration = $declarations[0];
        $drivers = [];
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            $drivers[$driver] = ['statements' => (new SchemaSql())->generate($declaration['operations'], $driver), 'transactional' => $driver !== 'mysql'];
        }
        $snapshot = ['protocol' => SchemaSql::PROTOCOL, 'declaration' => $this->identity($declaration), 'drivers' => $drivers];
        $snapshot['sha256'] = BuildIdentity::digest($snapshot);
        $bytes = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (strlen($bytes) > 1048576) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：冻结结果不得超过1MiB');
        }
        $target = $declaration['snapshot'];
        $this->assertSnapshotPath($declaration['source'], $target);
        if (is_file($target)) {
            if (file_get_contents($target) !== $bytes) {
                throw new RuntimeException('TYPE_SCHEMA_FROZEN：已有快照不得覆盖，请新增迁移版本和快照路径');
            }
            return ['snapshot' => $target, 'sha256' => $snapshot['sha256'], 'created' => false];
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('TYPE_SCHEMA_WRITE_FAILED：无法建立快照目录');
        }
        $this->assertSnapshotPath($declaration['source'], $target);
        $temporary = $target . '.tmp-' . bin2hex(random_bytes(8));
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
                throw new RuntimeException('TYPE_SCHEMA_WRITE_FAILED：快照写入不完整');
            }
            // 同路径并发准备也不能覆盖已经冻结的不同内容。
            if (!@link($temporary, $target)) {
                throw new RuntimeException('TYPE_SCHEMA_WRITE_FAILED：快照目标已存在或不能原子发布');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
        return ['snapshot' => $target, 'sha256' => $snapshot['sha256'], 'created' => true];
    }

    /** 缓存身份在复用开发代次之前核验快照；不执行声明或生成新的 SQL。 */
    public function inputs(array $sources): array
    {
        $files = [];
        foreach ($this->declarations($sources) as $declaration) {
            $this->snapshot($declaration);
            $files[] = $declaration['snapshot'];
        }
        return array_values(array_unique($files));
    }

    /** 生成固定三库 Migration 工厂；生产只接收驱动名，不读取 PHP 声明或 JSON。 */
    public function compile(array $sources): array
    {
        $code = "<?php\ndeclare(strict_types=1);\n";
        $originals = [];
        $files = [];
        $reports = [];
        foreach ($this->declarations($sources) as $declaration) {
            $snapshot = $this->snapshot($declaration);
            $parts = explode('\\', $declaration['class']);
            $class = array_pop($parts);
            $namespace = implode('\\', $parts);
            $code .= "\nnamespace " . $namespace . " {\nfinal class " . $class . "\n{\n";
            $code .= "    /** 消费已审查的冻结 SQL，事务与失败恢复由 Migrator 负责。 */\n    public static function migration(string \$driver): \\Type\\Orm\\Migration\\Migration\n    {\n";
            foreach ($snapshot['drivers'] as $driver => $plan) {
                $code .= '        if ($driver === ' . var_export($driver, true) . ") {\n            return new \\Type\\Orm\\Migration\\Migration("
                    . var_export($declaration['version'], true) . ', ' . var_export($declaration['description'], true) . ', '
                    . var_export($plan['statements'], true) . ', ' . ($plan['transactional'] ? 'true' : 'false') . ");\n        }\n";
            }
            $code .= "        throw new \\Type\\Orm\\Migration\\MigrationException('TYPE_SCHEMA_UNSUPPORTED：未知迁移驱动');\n    }\n}\n}\n";
            $originals[] = $declaration['source'];
            $files[] = $declaration['snapshot'];
            $reports[] = ['class' => $declaration['class'], 'version' => $declaration['version'], 'protocol' => $snapshot['protocol'],
                'declaration' => $snapshot['declaration'], 'sha256' => $snapshot['sha256']];
        }
        (new ParserFactory())->createForNewestSupportedVersion()->parse($code);
        return ['code' => $code, 'originals' => array_values(array_unique($originals)), 'files' => array_values(array_unique($files)), 'schemas' => $reports];
    }

    private function declarations(array $sources): array
    {
        $declarations = [];
        $seen = [];
        $finder = new NodeFinder();
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ((new BuildIdentity())->sources($sources) as $source) {
            if (pathinfo($source, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }
            $bytes = (string) file_get_contents($source);
            // 属性只能由 #[ 起始；仅提及 Schema 的普通实现不需要解析声明 AST。
            if (!str_contains($bytes, '#[') || !str_contains($bytes, 'Schema')) {
                continue;
            }
            $nodes = (new NodeTraverser(new NameResolver()))->traverse($parser->parse($bytes) ?? []);
            $classes = $finder->findInstanceOf($nodes, Node\Stmt\ClassLike::class);
            $found = [];
            foreach ($classes as $class) {
                foreach ($class->attrGroups as $group) {
                    foreach ($group->attrs as $attribute) {
                        if ($attribute->name->toString() !== 'Type\\Orm\\Attribute\\Schema') {
                            continue;
                        }
                        if (!$class instanceof Node\Stmt\Class_ || $class->name === null || $class->stmts !== [] || $class->extends !== null || $class->implements !== []) {
                            throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 迁移类必须独立且不声明运行成员、继承或接口');
                        }
                        if ($class->isAbstract() || $class->isReadonly()) {
                            throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 迁移类不能为 abstract 或 readonly');
                        }
                        $data = [];
                        $names = ['version', 'description', 'operations', 'snapshot'];
                        foreach ($attribute->args as $index => $argument) {
                            $name = $argument->name?->toString() ?? ($names[$index] ?? '');
                            if (!in_array($name, $names, true) || array_key_exists($name, $data) || $argument->unpack) {
                                throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 参数未知或重复');
                            }
                            try {
                                $data[$name] = (new ConstExprEvaluator())->evaluateDirectly($argument->value);
                            } catch (ConstExprEvaluationException $error) {
                                throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 参数仅接受静态常量', 0, $error);
                            }
                        }
                        if (!is_string($data['version'] ?? null) || preg_match('/^[0-9][A-Za-z0-9_.-]{0,63}$/D', $data['version']) !== 1
                            || !is_string($data['description'] ?? null) || $data['description'] === '' || strlen($data['description']) > 500
                            || !is_array($data['operations'] ?? null) || $data['operations'] === [] || !array_is_list($data['operations'])
                            || !is_string($data['snapshot'] ?? null) || preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.json$#D', $data['snapshot']) !== 1) {
                            throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 版本、说明、操作或相对快照路径无效');
                        }
                        $data['class'] = $class->namespacedName->toString();
                        $data['source'] = $source;
                        $data['snapshot'] = dirname($source) . '/' . $data['snapshot'];
                        if (isset($seen[$data['snapshot']]) || isset($seen[strtolower($data['class'])])) {
                            throw new RuntimeException('TYPE_SCHEMA_INVALID：迁移类或冻结路径重复');
                        }
                        $seen[$data['snapshot']] = true;
                        $seen[strtolower($data['class'])] = true;
                        $found[] = $data;
                    }
                }
            }
            if ($found !== []) {
                if (count($classes) !== count($found) || $finder->findInstanceOf($nodes, Node\Stmt\Function_::class) !== []) {
                    throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 源文件不能混入其他业务类或函数');
                }
                foreach ($nodes as $node) {
                    if (!$node instanceof Node\Stmt\Namespace_ && !$node instanceof Node\Stmt\Declare_ && !$node instanceof Node\Stmt\Nop) {
                        throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 文件只接受具名命名空间中的独立声明');
                    }
                    if ($node instanceof Node\Stmt\Declare_ && ($node->stmts !== null || count($node->declares) !== 1 || $node->declares[0]->key->toString() !== 'strict_types')) {
                        throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 仅接受 strict_types 声明');
                    }
                    if ($node instanceof Node\Stmt\Namespace_) {
                        if ($node->name === null) {
                            throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 需要具名命名空间');
                        }
                        foreach ($node->stmts as $statement) {
                            if (!$statement instanceof Node\Stmt\Class_ && !$statement instanceof Node\Stmt\Use_ && !$statement instanceof Node\Stmt\GroupUse && !$statement instanceof Node\Stmt\Nop) {
                                throw new RuntimeException('TYPE_SCHEMA_INVALID：Schema 声明不能包含可执行语句');
                            }
                        }
                    }
                }
                array_push($declarations, ...$found);
            }
        }
        return $declarations;
    }

    private function snapshot(array $declaration): array
    {
        $file = $declaration['snapshot'];
        $this->assertSnapshotPath($declaration['source'], $file);
        if (!is_file($file) || filesize($file) > 1048576) {
            throw new RuntimeException('TYPE_SCHEMA_SNAPSHOT_MISSING：先显式执行 schema:prepare 并审查冻结结果');
        }
        try {
            $snapshot = json_decode((string) file_get_contents($file), true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RuntimeException('TYPE_SCHEMA_SNAPSHOT_CHANGED：冻结结果不是有效 JSON', 0, $error);
        }
        if (!is_array($snapshot) || ($snapshot['protocol'] ?? null) !== SchemaSql::PROTOCOL || ($snapshot['declaration'] ?? null) !== $this->identity($declaration)
            || !is_string($snapshot['sha256'] ?? null) || !is_array($snapshot['drivers'] ?? null) || array_keys($snapshot['drivers'] ?? []) !== ['mysql', 'pgsql', 'sqlite']) {
            throw new RuntimeException('TYPE_SCHEMA_SNAPSHOT_CHANGED：冻结协议、声明或三库内容不匹配');
        }
        $payload = $snapshot;
        unset($payload['sha256']);
        if (BuildIdentity::digest($payload) !== $snapshot['sha256']) {
            throw new RuntimeException('TYPE_SCHEMA_SNAPSHOT_CHANGED：冻结 SQL 摘要不匹配');
        }
        foreach ($snapshot['drivers'] as $driver => $plan) {
            if (!is_array($plan) || ($plan['transactional'] ?? null) !== ($driver !== 'mysql') || !is_array($plan['statements'] ?? null)
                || !array_is_list($plan['statements']) || $plan['statements'] === []) {
                throw new RuntimeException('TYPE_SCHEMA_SNAPSHOT_CHANGED：冻结 SQL 或事务策略无效');
            }
            foreach ($plan['statements'] as $sql) {
                if (!is_string($sql) || trim($sql) === '') {
                    throw new RuntimeException('TYPE_SCHEMA_SNAPSHOT_CHANGED：冻结 SQL 必须为非空字符串');
                }
            }
        }
        return $snapshot;
    }

    private function identity(array $declaration): string
    {
        return hash('sha256', json_encode(['class' => $declaration['class'], 'version' => $declaration['version'],
            'description' => $declaration['description'], 'operations' => $declaration['operations']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertSnapshotPath(string $source, string $snapshot): void
    {
        $root = dirname($source);
        $existing = is_file($snapshot) ? $snapshot : dirname($snapshot);
        while (!file_exists($existing) && $existing !== dirname($existing)) {
            $existing = dirname($existing);
        }
        $resolved = BuildPlatform::resolve($existing);
        if (is_link($snapshot) || ($resolved !== $root && !BuildPlatform::contains($root, $resolved))) {
            throw new RuntimeException('TYPE_SCHEMA_INVALID：快照路径不能通过链接越出声明目录');
        }
    }
}
