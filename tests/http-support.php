<?php

declare(strict_types=1);

function httpRequest(int $port, string $method, string $path, string $marker = '', string $body = ''): array
{
    return receiveHttp(sendHttp($port, $method, $path, $marker, $body));
}

function sendHttp(int $port, string $method, string $path, string $marker = '', string $body = '')
{
    $connection = stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $error, 3);
    expect(is_resource($connection), 'HTTP 连接失败：' . $error);
    stream_set_timeout($connection, 3);
    $wire = $method . ' ' . $path . " HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\nX-Test-Marker: " . $marker
        . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body;
    expect(fwrite($connection, $wire) === strlen($wire), 'HTTP 请求写入不完整');
    return $connection;
}

function receiveHttp($connection): array
{
    try {
        $response = stream_get_contents($connection);
        expect(!stream_get_meta_data($connection)['timed_out'], 'HTTP 响应超时');
        expect(str_contains($response, "\r\n\r\n"), 'HTTP 响应格式无效：' . $response);
        [$headers, $body] = explode("\r\n\r\n", $response, 2);
        if (str_contains(strtolower($headers), 'transfer-encoding: chunked')) {
            $decoded = '';
            $offset = 0;
            while (true) {
                $end = strpos($body, "\r\n", $offset);
                expect($end !== false, '分块响应缺少长度行');
                $length = hexdec(explode(';', substr($body, $offset, $end - $offset))[0]);
                $offset = $end + 2;
                if ($length === 0) {
                    break;
                }
                expect(strlen($body) >= $offset + $length + 2 && substr($body, $offset + $length, 2) === "\r\n", '分块响应提前结束');
                $decoded .= substr($body, $offset, $length);
                $offset += $length + 2;
            }
            $body = $decoded;
        }
        preg_match('/^HTTP\/1\.[01] (\d{3})/', $headers, $status);
        return [(int) ($status[1] ?? 0), $body, strtolower($headers)];
    } finally {
        fclose($connection);
    }
}
