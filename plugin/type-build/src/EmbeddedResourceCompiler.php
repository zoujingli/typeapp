<?php

declare(strict_types=1);

namespace Type\Build;

use RuntimeException;

/** 将显式静态资源生成原生常量；产物读取不依赖原始目录或解压运行库。 */
final class EmbeddedResourceCompiler
{
    /**
     * 收集受控目录，内容排序和原文摘要共同决定内嵌资源身份。
     * @param list<array{source:string,target:string}> $declarations 项目根相对目录及目标前缀。
     * @return array<string, array{source:string,bytes:int,sha256:string}> 按目标路径排序的文件。
     */
    public function collect(string $root, array $declarations): array
    {
        if (!array_is_list($declarations)) {
            throw new RuntimeException('embedded-resources 必须是声明列表');
        }
        $root = BuildPlatform::resolve($root);
        $files = [];
        $folded = [];
        $total = 0;
        foreach ($declarations as $declaration) {
            if (!is_array($declaration) || !is_string($declaration['source'] ?? null) || !is_string($declaration['target'] ?? null)) {
                throw new RuntimeException('内嵌资源需要 source 目录和 target 前缀');
            }
            $this->relative($declaration['source']);
            $this->relative($declaration['target']);
            $directory = $root;
            foreach (explode('/', $declaration['source']) as $segment) {
                $directory .= '/' . $segment;
                if (is_link($directory) || !is_dir($directory)) {
                    throw new RuntimeException('内嵌资源来源必须是项目内普通目录');
                }
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    throw new RuntimeException('内嵌资源不接受符号链接');
                }
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
                $this->relative($relative);
                if ($entry->isDir()) {
                    continue;
                }
                if (!$entry->isFile() || preg_match('/\.(?:php[0-9]?|phtml|phar|inc|so|dylib|dll|pem|key)$/iD', $relative)
                    || preg_match('~(?:^|/)(?:auth\.json|id_(?:rsa|ed25519|ecdsa|dsa))$~iD', $relative)) {
                    throw new RuntimeException('内嵌资源不接受源码、原生库或秘密文件');
                }
                $target = $declaration['target'] . '/' . $relative;
                if (isset($folded[strtolower($target)])) {
                    throw new RuntimeException('内嵌资源目标重复或存在大小写冲突：' . $target);
                }
                $bytes = $entry->getSize();
                $total += $bytes;
                if (count($files) >= 1000 || $total > 67108864) {
                    throw new RuntimeException('内嵌资源超过1000个文件或64MiB构建预算');
                }
                $files[$target] = ['source' => $entry->getPathname(), 'bytes' => $bytes, 'sha256' => (string) hash_file('sha256', $entry->getPathname())];
                $folded[strtolower($target)] = true;
            }
        }
        ksort($files);
        if ($declarations !== [] && $files === []) {
            throw new RuntimeException('声明的内嵌资源目录为空');
        }
        return $files;
    }

    /** @param array<string, array{source:string,bytes:int,sha256:string}> $files */
    public function manifest(array $files): array
    {
        $manifest = [];
        foreach ($files as $path => $file) {
            $manifest[$path] = ['bytes' => $file['bytes'], 'sha256' => $file['sha256']];
        }
        $this->validate($manifest);
        return $manifest;
    }

    /**
     * 静态读取产物时也验证资源协议；不执行待检查程序或依赖构建端文件。
     * @param array<string,array{bytes:int,sha256:string}> $manifest 程序内资源身份。
     * @throws RuntimeException 清单条目、路径、大小或跨平台映射无效。
     */
    public function validate(array $manifest): void
    {
        $paths = [];
        $total = 0;
        foreach ($manifest as $path => $file) {
            if (!is_string($path) || !is_array($file) || !is_int($file['bytes'] ?? null) || $file['bytes'] < 0
                || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])
                || count($file) !== 2) {
                throw new RuntimeException('内嵌资源清单格式无效');
            }
            $this->relative($path);
            $key = strtolower($path);
            if (isset($paths[$key]) || preg_match('/\.(?:php[0-9]?|phtml|phar|inc|so|dylib|dll|pem|key)$/iD', $path)
                || preg_match('~(?:^|/)(?:auth\.json|id_(?:rsa|ed25519|ecdsa|dsa))$~iD', $path)) {
                throw new RuntimeException('内嵌资源清单存在冲突或秘密路径');
            }
            $paths[$key] = true;
            $total += $file['bytes'];
            if (count($paths) > 1000 || $total > 67108864) {
                throw new RuntimeException('内嵌资源清单超出预算');
            }
        }
        foreach (array_keys($paths) as $path) {
            for ($parent = dirname($path); $parent !== '.'; $parent = dirname($parent)) {
                if (isset($paths[$parent])) {
                    throw new RuntimeException('内嵌资源文件与目录冲突');
                }
            }
        }
    }

    /**
     * C++数组绕开平台字符串常量长度限制；读取每次最多64KiB，不在启动时复制整份载荷。
     * @param array<string, array{source:string,bytes:int,sha256:string}> $files
     * @return array<string,string> 同次AOT使用的桥接声明、数据及访问类。
     */
    public function sources(array $files): array
    {
        $manifest = var_export($this->manifest($files), true);
        $php = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Type\\Generated;\n\n/** 当前产物的只读内嵌资源，安装时按块消费。 */\nfinal class EmbeddedResources\n{\n"
            . "    public static function manifest(): array { return {$manifest}; }\n"
            . "    public static function read(string \$path, int \$offset, int \$length): string\n    {\n"
            . "        \$files = self::manifest();\n"
            . "        if (!isset(\$files[\$path]) || \$offset < 0 || \$offset > \$files[\$path]['bytes'] || \$length < 0 || \$length > 65536) { throw new \\InvalidArgumentException('内嵌资源读取范围无效'); }\n"
            . "        return \\type_app_embedded_resource_read(\$path, \$offset, \$length);\n    }\n}\n";
        $cpp = "#include <phpx.h>\n#include <algorithm>\n#include <cstring>\n\n";
        $entries = [];
        foreach ($files as $path => $file) {
            $contents = file_get_contents($file['source']);
            if ($contents === false || strlen($contents) !== $file['bytes'] || hash('sha256', $contents) !== $file['sha256']) {
                throw new RuntimeException('生成内嵌资源期间输入改变：' . $path);
            }
            $id = count($entries);
            $cpp .= 'static const unsigned char resource_' . $id . "[] = {\n";
            foreach (str_split($contents, 64) as $chunk) {
                $cpp .= implode(',', array_values(unpack('C*', $chunk))) . ",\n";
            }
            $cpp .= "0};\n";
            $entries[] = '{"' . $path . '", resource_' . $id . ', ' . $file['bytes'] . '}';
        }
        $cpp .= "struct TypeEmbeddedFile { const char* name; const unsigned char* bytes; size_t size; };\n"
            . 'static const TypeEmbeddedFile resources[] = {' . ($entries === [] ? '' : implode(",\n", $entries) . ',') . "{nullptr,nullptr,0}};\n"
            . <<<'CPP'
php::String php_type_app_embedded_resource_read(php::String path, php::Int offset, php::Int length) {
    if (offset < 0 || length < 0 || length > 65536) { return php::String(""); }
    for (const auto& resource : resources) {
        if (resource.name && path.length() == std::strlen(resource.name) && std::memcmp(path.data(), resource.name, path.length()) == 0) {
            if (static_cast<size_t>(offset) > resource.size) { return php::String(""); }
            const size_t count = std::min(static_cast<size_t>(length), resource.size - static_cast<size_t>(offset));
            return php::String(reinterpret_cast<const char*>(resource.bytes + offset), count);
        }
    }
    return php::String("");
}
CPP;
        $stub = "<?php\n\ndeclare(strict_types=1);\n\n/** @internal 由同次链接的原生常量数据实现。 */\nfunction type_app_embedded_resource_read(string \$path, int \$offset, int \$length): string {}\n";
        // TypePHP按去掉.stub后的文件名生成参数头；访问类须使用不同文件名。
        return ['embedded-resource-accessor.php' => $php, 'embedded-resources.stub.php' => $stub, 'embedded-resources.cc' => $cpp . "\n"];
    }

    /** 拒绝隐藏文件、路径跳转和跨平台设备名，避免目标语义随构建平台改变。 */
    private function relative(string $path): void
    {
        if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._/-]*$~D', $path)) {
            throw new RuntimeException('内嵌资源路径必须是安全相对路径');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || str_starts_with($part, '.') || str_ends_with($part, '.')
                || preg_match('/^(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/iD', $part)) {
                throw new RuntimeException('内嵌资源路径包含不允许的片段');
            }
        }
    }
}
