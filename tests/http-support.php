<?php

declare(strict_types=1);

/**
 * 发送并读取一次独立 HTTP 请求，返回状态、正文和小写响应头，连接在读取后关闭。
 *
 * @return array{int, string, string}
 */
function httpRequest(int $port, string $method, string $path, string $marker = '', string $body = ''): array
{
    return receiveHttp(sendHttp($port, $method, $path, $marker, $body));
}

/**
 * 发送带测试标记的 JSON 请求，保留连接用于并发观察；调用方须交给 receiveHttp 读取并关闭。
 *
 * @return resource
 */
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

/**
 * 读取 HTTP 响应并解码 chunked 正文，返回状态及原始头文本；成功或失败均关闭传入连接。
 *
 * @param resource $connection 所有权在调用时转入本函数。
 * @return array{int, string, string} 状态码、正文、小写响应头。
 */
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
