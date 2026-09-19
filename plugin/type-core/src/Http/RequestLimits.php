<?php

declare(strict_types=1);

namespace Type\Core\Http;

/** 传输字节上限由所选 HTTP 引擎在读取时执行；解析前再校验结构预算。 */
final class RequestLimits
{
    public function __construct(
        public readonly int $bytes = 1048576,
        public readonly int $fields = 100,
        public readonly int $depth = 16,
        public readonly int $uploads = 8,
        public readonly int $fileBytes = 1048576,
        public readonly int $fieldBytes = 65536,
        public readonly ?string $temporaryDirectory = null,
        public readonly int $temporaryBytes = 67108864,
    ) {
        if ($bytes < 1 || $bytes > 67108864 || $fields < 1 || $fields > 10000 || $depth < 2 || $depth > 128
            || $uploads < 0 || $uploads > 128 || $fileBytes < 1 || $fileBytes > $bytes || $fieldBytes < 1 || $fieldBytes > $bytes) {
            throw new \InvalidArgumentException('HTTP 输入预算无效');
        }
        if ($temporaryBytes < $bytes) {
            throw new \InvalidArgumentException('接入层临时空间不得小于单次请求上限');
        }
        if ($temporaryDirectory !== null) {
            $capacity = @disk_total_space($temporaryDirectory);
            if (!is_dir($temporaryDirectory) || is_link($temporaryDirectory) || !is_writable($temporaryDirectory)
                || $capacity === false || $capacity > $temporaryBytes) {
                throw new \InvalidArgumentException('multipart 临时目录必须位于容量不超过声明值的专用文件系统');
            }
        }
    }

    public function json(string $content): void
    {
        $stack = [];
        $quoted = false;
        $escape = false;
        $fields = 0;
        $length = strlen($content);
        for ($offset = 0; $offset < $length; $offset++) {
            $character = $content[$offset];
            if ($quoted) {
                if ($escape) {
                    $escape = false;
                } elseif ($character === '\\') {
                    $escape = true;
                } elseif ($character === '"') {
                    $quoted = false;
                }
                continue;
            }
            if (str_contains(" \r\n\t", $character)) {
                continue;
            }
            $top = count($stack) - 1;
            if ($top >= 0 && $stack[$top] === 'next' && $character !== ']') {
                $fields++;
                $stack[$top] = 'array';
            }
            if ($character === '"') {
                $quoted = true;
            } elseif ($character === '[' || $character === '{') {
                $stack[] = $character === '[' ? 'next' : 'object';
                if (count($stack) > $this->depth) {
                    throw new HttpError(413, 'input_too_deep');
                }
            } elseif ($character === ']' || $character === '}') {
                array_pop($stack);
            } elseif ($character === ':') {
                $fields++;
            } elseif ($character === ',' && $top >= 0 && $stack[$top] === 'array') {
                $stack[$top] = 'next';
            }
            if ($fields > $this->fields) {
                throw new HttpError(413, 'too_many_fields');
            }
        }
    }
}
