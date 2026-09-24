<?php

declare(strict_types=1);

namespace Type\Core\Http\Message;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;

/** PSR-7 上传内容及客户端元数据，成功移动后禁止再次读取或移动。 */
final class UploadedFile implements UploadedFileInterface
{
    private StreamInterface $stream;
    private ?int $size;
    private int $error;
    private ?string $filename;
    private ?string $mediaType;
    private bool $moved = false;

    /**
     * 验证上传错误码和可读性；size 以字节计，null 尝试从流读取大小。
     * @param string|null $filename 客户端声明名称，不能直接作为可信存储路径。
     * @param string|null $mediaType 客户端声明媒体类型，不能替代内容检查。
     */
    public function __construct(
        StreamInterface $stream,
        ?int $size = null,
        int $error = UPLOAD_ERR_OK,
        ?string $filename = null,
        ?string $mediaType = null
    ) {
        if (!in_array($error, [UPLOAD_ERR_OK, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)
            || ($size !== null && $size < 0) || ($error === UPLOAD_ERR_OK && !$stream->isReadable())) {
            throw new InvalidArgumentException('上传文件流、大小或错误码无效');
        }
        $this->stream = $stream;
        $this->size = $size ?? $stream->getSize();
        $this->error = $error;
        $this->filename = $filename;
        $this->mediaType = $mediaType;
    }

    /** 返回共享上传流；上传失败或已移动时抛出 RuntimeException。 */
    public function getStream(): StreamInterface
    {
        $this->assertAvailable();
        return $this->stream;
    }

    /**
     * 把内容移至本地目标并关闭原流；目标目录由调用方准备，失败由调用方处理。
     * @throws RuntimeException 内容复制、移动、源清理或关闭失败；异常时需核对目标是否已生成。
     */
    public function moveTo(string $targetPath): void
    {
        $this->assertAvailable();
        if ($targetPath === '' || str_contains($targetPath, "\0") || str_contains($targetPath, '://')) {
            throw new InvalidArgumentException('上传文件目标路径无效');
        }
        $directory = dirname($targetPath);
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('上传文件目标目录不可写');
        }
        $source = (string) $this->stream->getMetadata('uri');
        $sourceFile = $source !== '' && !str_contains($source, '://') && is_file($source);
        if ($sourceFile && realpath($source) === realpath($targetPath)) {
            $this->stream->close();
            $this->moved = true;
            return;
        }
        if ($sourceFile && is_uploaded_file($source)) {
            if (!move_uploaded_file($source, $targetPath)) {
                throw new RuntimeException('无法移动 SAPI 上传文件');
            }
            $this->stream->close();
            $this->moved = true;
            return;
        }
        $temporary = tempnam($directory, '.type-upload-');
        if ($temporary === false) {
            throw new RuntimeException('无法创建上传文件目标');
        }
        $output = @fopen($temporary, 'wb');
        if ($output === false) {
            unlink($temporary);
            throw new RuntimeException('无法打开上传文件目标');
        }
        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }
            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(8192);
                if ($chunk === '' && !$this->stream->eof()) {
                    throw new RuntimeException('上传流读取没有进展');
                }
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $written = @fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('上传文件写入失败');
                    }
                    $offset += $written;
                }
            }
            if (!fflush($output)) {
                throw new RuntimeException('上传文件缓冲写入失败');
            }
            fclose($output);
            $output = null;
            if (!@rename($temporary, $targetPath)) {
                throw new RuntimeException('无法完成上传文件移动');
            }
            $this->stream->close();
            $this->moved = true;
            if ($sourceFile && is_file($source) && !@unlink($source)) {
                throw new RuntimeException('上传内容已移动，但原文件无法删除');
            }
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** 返回构造时声明或观测的字节大小，未知时为 null。 */
    public function getSize(): ?int
    {
        return $this->size;
    }

    /** 返回 PHP UPLOAD_ERR_* 错误码，不把失败上传伪装为空文件。 */
    public function getError(): int
    {
        return $this->error;
    }

    /** 返回未经信任的客户端文件名；不得直接拼接为存储路径。 */
    public function getClientFilename(): ?string
    {
        return $this->filename;
    }

    /** 返回未经信任的客户端媒体类型；文件内容仍由应用独立校验。 */
    public function getClientMediaType(): ?string
    {
        return $this->mediaType;
    }

    private function assertAvailable(): void
    {
        if ($this->moved || $this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('上传文件已经移动或上传失败');
        }
    }
}
