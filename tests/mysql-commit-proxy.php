<?php

declare(strict_types=1);

// 仅用于测试：转发原始 MySQL 数据包，在指定确认边界切断一次连接。
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($listener === false) {
    throw new RuntimeException('无法启动故障代理');
}
$port = (int) substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
echo $port . PHP_EOL;
flush();
$client = stream_socket_accept($listener, 10);
if ($client === false) {
    throw new RuntimeException('故障代理没有收到客户端');
}
$backend = stream_socket_client('tcp://' . getenv('TYPE_PROXY_HOST') . ':' . getenv('TYPE_PROXY_PORT'), $errno, $error, 5);
if ($backend === false) {
    throw new RuntimeException('故障代理无法连接测试数据库');
}
stream_set_blocking($client, false);
stream_set_blocking($backend, false);
$mode = getenv('TYPE_PROXY_MODE');
$buffers = ['client' => '', 'server' => ''];
$waiting = false;
$marker = '';
$deadline = microtime(true) + 30;

function proxyWrite($target, string $bytes, float $deadline): void
{
    $offset = 0;
    while ($offset < strlen($bytes)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('故障代理写入超时');
        }
        $read = [];
        $write = [$target];
        $except = [];
        if (stream_select($read, $write, $except, 0, 100000) === false) {
            throw new RuntimeException('代理连接不可写');
        }
        if ($write === []) {
            continue;
        }
        $count = fwrite($target, substr($bytes, $offset));
        if ($count === false || ($count === 0 && feof($target))) {
            throw new RuntimeException('代理连接提前断开');
        }
        $offset += $count;
    }
}

try {
    while (microtime(true) < $deadline && $marker === '') {
        $read = [$client, $backend];
        $write = [];
        $except = [];
        if (stream_select($read, $write, $except, 0, 100000) === false) {
            throw new RuntimeException('代理读取失败');
        }
        foreach ($read as $source) {
            $key = $source === $client ? 'client' : 'server';
            $chunk = fread($source, 8192);
            if ($chunk === false || ($chunk === '' && feof($source))) {
                throw new RuntimeException('确认边界前连接已结束');
            }
            $buffers[$key] .= $chunk;
            if (strlen($buffers[$key]) > 1048576) {
                throw new RuntimeException('故障验证数据包超限');
            }
            while (strlen($buffers[$key]) >= 4) {
                $length = ord($buffers[$key][0]) | (ord($buffers[$key][1]) << 8) | (ord($buffers[$key][2]) << 16);
                if ($length > 1048572) {
                    throw new RuntimeException('故障验证不接受超大包或加密协议');
                }
                if (strlen($buffers[$key]) < $length + 4) {
                    break;
                }
                $packet = substr($buffers[$key], 0, $length + 4);
                $buffers[$key] = substr($buffers[$key], $length + 4);
                $payload = substr($packet, 4);
                if ($key === 'client' && str_starts_with($payload, "\x03")) {
                    $sql = strtoupper(trim(substr($payload, 1)));
                    if ($mode === 'begin' && in_array($sql, ['START TRANSACTION', 'BEGIN'], true)) {
                        $marker = 'dropped_begin';
                        break 2;
                    }
                    if ($sql === 'COMMIT') {
                        if ($mode === 'before') {
                            $marker = 'dropped_before_commit';
                            break 2;
                        }
                        $waiting = true;
                    }
                }
                if ($key === 'server' && $waiting) {
                    if (!str_starts_with($payload, "\x00")) {
                        throw new RuntimeException('数据库没有确认提交成功');
                    }
                    $marker = 'commit_confirmed_and_dropped';
                    break 2;
                }
                proxyWrite($key === 'client' ? $backend : $client, $packet, $deadline);
            }
        }
    }
    if ($marker === '') {
        throw new RuntimeException('没有观察到指定事务边界');
    }
    echo $marker . PHP_EOL;
} finally {
    fclose($client);
    fclose($backend);
    fclose($listener);
}
