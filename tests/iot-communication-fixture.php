<?php

declare(strict_types=1);

/**
 * 通过控制端签发和公开注册准备真实设备，不直接写入资格或凭据表。
 * @param Closure(string,string,string,?string,?array,int):array $request 已有 HTTP 状态断言入口。
 * @return array<string,mixed> 注册响应，凭据仅保存在本轮内存中。
 */
function iotRegisteredFixture(Closure $request, string $platformToken, string $tenant, string $name): array
{
    $grant = $request('POST', '/admin/device-authorizations', $platformToken, null, [
        'id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'name' => $name, 'capacity' => 1,
        'register_expires_at' => time() + 3600,
    ], 201)['data'];
    return $request('POST', '/public/devices/register', '', null, [
        'id' => bin2hex(random_bytes(16)), 'tenant_id' => $tenant, 'device_id' => bin2hex(random_bytes(16)), 'name' => $name, 'code' => $grant['code'],
    ], 201)['data'];
}

/**
 * 创建真实接入应用，并只授予传入设备当前关系的原始通信权限。
 * @param Closure(string,string,string,?string,?array,int):array $request 已有 HTTP 状态断言入口。
 * @param list<array<string,mixed>> $registrations 已由公开入口取得的注册响应。
 * @return array<string,mixed> 一次性交付的应用身份；调用者负责脱敏和资源回收。
 */
function iotApplicationFixture(Closure $request, string $ownerToken, string $tenant, array $registrations, string $name): array
{
    $path = '/customer/tenants/' . $tenant . '/applications';
    $created = $request('POST', $path, $ownerToken, $tenant, ['id' => bin2hex(random_bytes(16)), 'name' => $name], 201)['data'];
    // 创建应用和逐设备授权各自发布版本；活动节点必须真实确认前一个版本后才能继续。
    $settled = static function () use ($request, $path, $created, $ownerToken, $tenant): void {
        $until = microtime(true) + 15;
        do {
            $current = $request('GET', $path . '/' . $created['application']['id'], $ownerToken, $tenant, null, 200)['data']['application'];
            if ($current['publication']['status'] === 'effective') {
                return;
            }
            usleep(50000);
        } while (microtime(true) < $until);
        throw new RuntimeException('接入应用发布未被节点确认：' . $current['publication']['status']);
    };
    $settled();
    foreach ($registrations as $registration) {
        $device = $registration['device'];
        $request('POST', $path . '/' . $created['application']['id'] . '/devices/' . $device['id'], $ownerToken, $tenant, [
            'ownership_id' => $device['ownership_id'], 'version' => 0, 'receive' => true, 'send' => true, 'subscribe' => true,
        ], 200);
        $settled();
    }
    return $created['credential'];
}
