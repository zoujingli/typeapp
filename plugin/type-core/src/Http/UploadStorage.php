<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Psr\Http\Message\StreamInterface;
use Type\Runtime\ExecutionScope;

/** 存储目录专用于本应用；所有写入受同一文件锁与配额约束。 */
final class UploadStorage
{
    private string $directory;
    private int $maximumBytes;
    private int $maximumFiles;
    public function __construct(string $directory, int $maximumBytes = 67108864, int $maximumFiles = 128)
    {
        if (!is_dir($directory) || is_link($directory) || !is_writable($directory) || $maximumBytes < 1 || $maximumFiles < 1 || $maximumFiles > 10000) {
            throw new \InvalidArgumentException('上传存储目录或配额无效');
        }
        $this->directory = realpath($directory);
        $this->maximumBytes = $maximumBytes;
        $this->maximumFiles = $maximumFiles;
    }
    public function receive(StreamInterface $stream, ExecutionScope $scope, int $maximumFileBytes = 1048576): PendingUpload
    {
        $scope->assertActive();
        if ($maximumFileBytes < 1 || $maximumFileBytes > $this->maximumBytes) {
            throw new \InvalidArgumentException('单文件配额无效');
        }
        $key = bin2hex(random_bytes(16));
        $upload = new PendingUpload($this, $scope, $key);
        $scope->open($upload);
        try {
            if ($stream->isSeekable()) {
                $stream->rewind();
            }
            $bytes = 0;
            while (!$stream->eof()) {
                $scope->assertActive();
                $chunk = $stream->read(16384);
                $bytes += strlen($chunk);
                if ($bytes > $maximumFileBytes) {
                    throw new HttpError(413, 'upload_too_large');
                }
                if ($chunk === '' && !$stream->eof()) {
                    throw new HttpError(400, 'upload_stalled');
                }
                if ($chunk !== '') {
                    $this->append($key, $chunk);
                }
            }
        } catch (\Throwable $error) {
            $upload->stop();
            throw $error;
        }
        return $upload;
    }
    public function create(string $key): void
    {
        $this->locked(function () use ($key): void {
            [$count] = $this->usage();
            if ($count >= $this->maximumFiles) {
                throw new HttpError(507, 'upload_file_quota');
            }
            $file = fopen($this->path($key, false), 'x+b');
            if ($file === false) {
                throw new HttpError(507, 'upload_write_failed');
            }
            fclose($file);
        });
    }
    public function append(string $key, string $bytes): void
    {
        $this->locked(function () use ($key, $bytes): void {
            [, $used] = $this->usage();
            if ($used + strlen($bytes) > $this->maximumBytes) {
                throw new HttpError(507, 'upload_disk_quota');
            }
            $path = $this->path($key, false);
            if (!is_file($path)) {
                throw new HttpError(507, 'upload_missing');
            }
            $file = @fopen($path, 'ab');
            if ($file === false) {
                throw new HttpError(507, 'upload_write_failed');
            }
            try {
                if (@fwrite($file, $bytes) !== strlen($bytes) || !@fflush($file)) {
                    throw new HttpError(507, 'upload_write_failed');
                }
            } finally {
                fclose($file);
            }
        });
    }
    public function commit(string $key): string
    {
        $this->locked(function () use ($key): void {
            $file = @fopen($this->path($key, false), 'r+b');
            if ($file === false) {
                throw new HttpError(507, 'upload_save_failed');
            }
            try {
                if (!@fsync($file)) {
                    throw new HttpError(507, 'upload_sync_failed');
                }
            } finally {
                fclose($file);
            }
            if (file_exists($this->path($key, true)) || !@rename($this->path($key, false), $this->path($key, true))) {
                throw new HttpError(507, 'upload_save_failed');
            }
        });
        return $key;
    }
    public function discard(string $key): void
    {
        // 取消只删除本上传拥有的临时文件；不等待配额锁，避免锁竞争留下无主文件。
        $file = $this->path($key, false);
        if (is_file($file) && !@unlink($file)) {
            throw new HttpError(507, 'upload_cleanup_failed');
        }
    }
    public function open(string $key): StreamInterface
    {
        $file = $this->path($key, true);
        if (!is_file($file) || is_link($file)) {
            throw new HttpError(404, 'file_not_found');
        }
        return (new Message\Factory())->createStreamFromFile($file);
    }
    public function remove(string $key): void
    {
        $this->locked(function () use ($key): void {
            $file = $this->path($key, true);
            if (is_file($file) && !unlink($file)) {
                throw new HttpError(507, 'file_remove_failed');
            }
        });
    }
    public function statistics(): array
    {
        return $this->locked(function (): array {
            [$count, $bytes, $pending] = $this->usage();
            return ['files' => $count, 'bytes' => $bytes, 'pending' => $pending];
        });
    }
    private function path(string $key, bool $saved): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new HttpError(400, 'invalid_storage_key');
        }
        $file = $this->directory . '/' . ($saved ? 'saved-' : 'pending-') . $key;
        if (is_link($file)) {
            throw new HttpError(400, 'invalid_storage_path');
        } return $file;
    }
    private function usage(): array
    {
        clearstatcache();
        $count = 0;
        $bytes = 0;
        $pending = 0;
        foreach (new \DirectoryIterator($this->directory) as $file) {
            if (!preg_match('/^(pending|saved)-[a-f0-9]{32}$/D', $file->getFilename())) {
                continue;
            }
            if ($file->isLink() || !$file->isFile()) {
                throw new HttpError(507, 'invalid_storage_entry');
            }
            $size = @filesize($file->getPathname());
            if ($size === false) {
                continue;
            } // 另一个进程可以同时取消自己的未保存上传。
            $count++;
            $bytes += $size;
            if (str_starts_with($file->getFilename(), 'pending-')) {
                $pending++;
            }
        }
        return [$count, $bytes, $pending];
    }
    private function locked(\Closure $operation): mixed
    {
        $path = $this->directory . '/.type-upload.lock';
        if (is_link($path)) {
            throw new HttpError(507, 'invalid_storage_lock');
        }
        $lock = fopen($path, 'c+b');
        if ($lock === false) {
            throw new HttpError(507, 'upload_lock_failed');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new HttpError(503, 'upload_busy');
            } return $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
