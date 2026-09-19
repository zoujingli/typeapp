<?php

declare(strict_types=1);

namespace Type\Core\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Type\Runtime\ManagedResource;
use Type\Runtime\QueryString;
use Type\Runtime\QueryStringException;

/** 有界内存解析；应用流随请求收尾，接入层暂存另由部署配额限制。 */
final class RequestBody implements ManagedResource
{
    private RequestLimits $limits;
    private array $streams = [];
    public function __construct(RequestLimits $limits)
    {
        $this->limits = $limits;
    }
    public function start(): void
    {
    }
    public function stop(): void
    {
        foreach ($this->streams as $stream) {
            $stream->close();
        } $this->streams = [];
    }

    /** 可重放读取，恢复原始指针；不可定位流需要显式选择消费语义。 */
    public static function read(StreamInterface $stream, int $maxBytes = 1048576, bool $consume = false): string
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('正文读取上限无效');
        }
        $seekable = $stream->isSeekable();
        if (!$seekable && !$consume) {
            throw new HttpError(400, 'body_not_replayable');
        }
        $position = $seekable ? $stream->tell() : 0;
        try {
            if ($seekable) {
                $stream->rewind();
            }
            $body = '';
            while (!$stream->eof()) {
                $chunk = $stream->read(min(8192, $maxBytes + 1 - strlen($body)));
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    throw new HttpError(413, 'payload_too_large');
                }
                if ($chunk === '' && !$stream->eof()) {
                    throw new HttpError(400, 'body_read_stalled');
                }
            }
            return $body;
        } finally {
            if ($seekable) {
                $stream->seek($position);
            }
        }
    }

    /**
     * 多行 Cookie 等价于分号连接；重复名称拒绝，沿用两个 HTTP 入口的相同字段预算。
     *
     * @param list<string> $headers 未经原生 Cookie 解析的请求头。
     * @return array<string, string>
     * @throws HttpError Cookie 名称、结构或字段数量不符合限制。
     */
    public function cookies(array $headers): array
    {
        $values = [];
        foreach ($headers as $header) {
            foreach (explode(';', $header) as $pair) {
                if (trim($pair) === '') {
                    continue;
                }
                $parts = explode('=', trim($pair), 2);
                if (count($parts) !== 2 || preg_match("/^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$/D", $parts[0]) !== 1
                    || array_key_exists($parts[0], $values)) {
                    throw new HttpError(400, 'invalid_cookie');
                }
                $values[$parts[0]] = rawurldecode($parts[1]);
                if (count($values) > $this->limits->fields) {
                    throw new HttpError(413, 'too_many_cookies');
                }
            }
        }
        return $values;
    }

    public function parse(ServerRequestInterface $request, string $content): ServerRequestInterface
    {
        if (strlen($content) > $this->limits->bytes) {
            throw new HttpError(413, 'payload_too_large');
        }
        $request = $request->withBody($this->stream($content));
        $type = $request->getHeaderLine('Content-Type');
        $media = strtolower(trim(explode(';', $type, 2)[0]));
        if ($media === 'application/json' || str_ends_with($media, '+json')) {
            $this->limits->json($content);
        } elseif ($media === 'application/x-www-form-urlencoded') {
            try {
                $fields = QueryString::parse($content, $this->limits->fields, $this->limits->bytes);
            } catch (QueryStringException $error) {
                throw new HttpError($error->reason() === 'too_many_fields' ? 413 : $error->status(), 'invalid_form');
            }
            foreach ($fields as $value) {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (strlen($item) > $this->limits->fieldBytes) {
                        throw new HttpError(413, 'field_too_large');
                    }
                }
            }
            $request = $request->withParsedBody($fields);
        } elseif ($media === 'multipart/form-data') {
            [$fields, $uploads] = $this->multipart($type, $content);
            $request = $request->withParsedBody($fields)->withUploadedFiles($uploads);
        }
        return $request;
    }

    private function multipart(string $type, string $content): array
    {
        if (!preg_match('/^multipart\/form-data\s*;\s*boundary=(?:"([A-Za-z0-9\x27()+_,.\/:=? -]{1,70})"|([A-Za-z0-9\x27()+_,.\/:=?-]{1,70}))\s*$/iD', $type, $match)) {
            throw new HttpError(400, 'invalid_multipart_boundary');
        }
        $boundary = '--' . ($match[1] !== '' ? $match[1] : $match[2]);
        $fields = [];
        $uploads = [];
        $kinds = [];
        $fieldCount = 0;
        $uploadCount = 0;
        $offset = 0;
        if (!str_starts_with($content, $boundary)) {
            throw new HttpError(400, 'invalid_multipart');
        }
        while (true) {
            $offset += strlen($boundary);
            if (substr($content, $offset, 2) === '--') {
                if (!in_array(substr($content, $offset + 2), ['', "\r\n"], true)) {
                    throw new HttpError(400, 'invalid_multipart');
                }
                return [$fields, $uploads];
            }
            if (substr($content, $offset, 2) !== "\r\n") {
                throw new HttpError(400, 'invalid_multipart');
            }
            $offset += 2;
            $headerEnd = strpos($content, "\r\n\r\n", $offset);
            if ($headerEnd === false || $headerEnd - $offset > 8192) {
                throw new HttpError(400, 'invalid_multipart_headers');
            }
            $headers = [];
            foreach (explode("\r\n", substr($content, $offset, $headerEnd - $offset)) as $line) {
                if (!preg_match('/^([A-Za-z0-9-]+):[ \t]*([^\x00-\x08\x0a-\x1f\x7f]*)$/D', $line, $header)) {
                    throw new HttpError(400, 'invalid_multipart_headers');
                }
                $name = strtolower($header[1]);
                if (isset($headers[$name])) {
                    throw new HttpError(400, 'invalid_multipart_headers');
                }
                $headers[$name] = $header[2];
            }
            if (isset($headers['content-transfer-encoding']) || !preg_match('/^form-data;\s*name="([^"\r\n]{1,128})"(?:;\s*filename="([^"\r\n]{0,255})")?$/iD', $headers['content-disposition'] ?? '', $disposition)) {
                throw new HttpError(400, 'invalid_multipart_disposition');
            }
            $isFile = array_key_exists(2, $disposition);
            if ($isFile ? ++$uploadCount > $this->limits->uploads : ++$fieldCount > $this->limits->fields) {
                throw new HttpError(413, $isFile ? 'too_many_uploads' : 'too_many_fields');
            }
            $start = $headerEnd + 4;
            $next = $start;
            do {
                $next = strpos($content, "\r\n" . $boundary, $next);
                if ($next === false) {
                    throw new HttpError(400, 'incomplete_multipart');
                }
                $suffix = substr($content, $next + 2 + strlen($boundary), 2);
                if ($suffix === '--' || $suffix === "\r\n") {
                    break;
                }
                $next += 2;
            } while (true);
            if ($next - $start > ($isFile ? $this->limits->fileBytes : $this->limits->fieldBytes)) {
                throw new HttpError(413, $isFile ? 'upload_too_large' : 'field_too_large');
            }
            $value = substr($content, $start, $next - $start);
            if ($isFile) {
                $file = new Message\UploadedFile($this->stream($value), strlen($value), UPLOAD_ERR_OK, $disposition[2], $headers['content-type'] ?? null);
                $this->field($uploads, $kinds, $disposition[1], $file, 'file');
            } else {
                $this->field($fields, $kinds, $disposition[1], $value, 'field');
            }
            $offset = $next + 2;
        }
    }

    private function field(array &$target, array &$kinds, string $key, mixed $value, string $kind): void
    {
        $list = str_ends_with($key, '[]');
        $key = $list ? substr($key, 0, -2) : $key;
        if ($key === '' || preg_match('/[\x00-\x1f\x7f\[\]]/', $key) || preg_match('//u', $key) !== 1) {
            throw new HttpError(400, 'invalid_form_field');
        }
        $identity = $kind . ($list ? ':list' : ':scalar');
        if (isset($kinds[$key]) && (!$list || $kinds[$key] !== $identity)) {
            throw new HttpError(400, 'duplicate_form_field');
        }
        $kinds[$key] = $identity;
        if ($list) {
            $target[$key][] = $value;
        } else {
            $target[$key] = $value;
        }
    }

    private function stream(string $content): StreamInterface
    {
        $resource = fopen('php://memory', 'w+b');
        if ($resource === false) {
            throw new \RuntimeException('无法创建有界请求流');
        }
        $stream = new Message\Stream($resource);
        $this->streams[] = $stream;
        $length = strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = $stream->write(substr($content, $offset, 16384));
            if ($written === 0) {
                throw new \RuntimeException('请求流写入没有进展');
            } $offset += $written;
        }
        $stream->rewind();
        return $stream;
    }
}
