<?php

declare(strict_types=1);

namespace Type\Cache;

use InvalidArgumentException;
use Type\Redis\RedisConnection;

/** 原子维护代次、键索引和旧代回收；只操作当前应用命名空间。 */
final class NamespaceStore
{
    private RedisConnection $redis;
    private string $root;
    private int $maxPayload;

    public function __construct(RedisConnection $redis, string $application, string $environment, string $format, int $maxPayload = 1048576)
    {
        if ($application === '' || $environment === '' || $format === '' || $maxPayload < 1 || $maxPayload > 16777216) {
            throw new InvalidArgumentException('缓存命名空间或载荷上限无效');
        }
        $this->redis = $redis;
        $this->root = 'type:cache:{' . hash('sha256', json_encode([$application, $environment, $format], JSON_THROW_ON_ERROR)) . '}';
        $this->maxPayload = $maxPayload;
    }

    public function identity(): string
    {
        return $this->root;
    }

    /** 同次读取固定代次，并用位置返回结果，避免 PHP 键转换影响映射。 */
    public function read(array $keys): array
    {
        $hashes = $this->keys($keys);
        $script = <<<'LUA'
local gen=redis.call('GET',KEYS[1]); if not gen then gen=ARGV[1]; redis.call('SET',KEYS[1],gen) end
local out={gen}; local total=0; for i=2,#ARGV do
 local key=KEYS[2]..':g:'..gen..':v:'..ARGV[i]
 local length=redis.call('STRLEN',key); total=total+length
 if total>16777216 then return redis.error_reply('cache batch too large') end
 if length>tonumber(KEYS[3]) then out[#out+1]=false else out[#out+1]=redis.call('GET',key) end
end
return out
LUA;
        $result = $this->redis->script(
            $script,
            [$this->root . ':active', $this->root, (string) $this->maxPayload],
            array_merge([bin2hex(random_bytes(16))], $hashes)
        );
        if (!is_array($result) || count($result) !== count($keys) + 1) {
            throw new CacheException('缓存读取结果不完整');
        }
        $generation = (string) array_shift($result);
        $this->generation($generation);
        return ['generation' => $generation, 'values' => $result];
    }

    /** generation 与回源时观察的代次不一致时不写入，避免 clear 后回填旧内容。 */
    public function write(string $generation, array $keys, array $payloads, ?int $ttlMilliseconds): bool
    {
        $this->generation($generation);
        $hashes = $this->keys($keys);
        if (count($keys) !== count($payloads) || !array_is_list($payloads) || ($ttlMilliseconds !== null && $ttlMilliseconds < 1)) {
            throw new InvalidArgumentException('缓存写入数量或 TTL 无效');
        }
        $arguments = [$generation, $ttlMilliseconds === null ? 0 : $ttlMilliseconds];
        $bytes = 0;
        foreach ($payloads as $index => $payload) {
            if (!is_string($payload) || strlen($payload) > $this->maxPayload) {
                throw new InvalidArgumentException('缓存载荷无效或超限');
            }
            $bytes += strlen($payload);
            if ($bytes > 16777216) {
                throw new InvalidArgumentException('缓存批次总载荷超出 16 MiB');
            }
            $arguments[] = $hashes[$index];
            $arguments[] = $payload;
        }
        $script = <<<'LUA'
if redis.call('GET',KEYS[1])~=ARGV[1] then return 0 end
local prefix=KEYS[2]..':g:'..ARGV[1]; local index=prefix..':index'
for i=3,#ARGV,2 do
 local key=prefix..':v:'..ARGV[i]
 if tonumber(ARGV[2])==0 then redis.call('SET',key,ARGV[i+1]); redis.call('SET',prefix..':permanent','1')
 else redis.call('SET',key,ARGV[i+1],'PX',ARGV[2]) end
 redis.call('SADD',index,ARGV[i])
end
if redis.call('EXISTS',prefix..':permanent')==1 then redis.call('PERSIST',index)
else local ttl=redis.call('PTTL',index); if ttl<tonumber(ARGV[2]) then redis.call('PEXPIRE',index,ARGV[2]) end end
return 1
LUA;
        return $this->redis->script($script, [$this->root . ':active', $this->root], $arguments) === 1;
    }

    public function delete(array $keys): bool
    {
        $hashes = $this->keys($keys);
        $script = <<<'LUA'
local gen=redis.call('GET',KEYS[1]); if not gen then return 1 end
local prefix=KEYS[2]..':g:'..gen
for i=1,#ARGV do redis.call('UNLINK',prefix..':v:'..ARGV[i]); redis.call('SREM',prefix..':index',ARGV[i]) end
return 1
LUA;
        return $this->redis->script($script, [$this->root . ':active', $this->root], $hashes) === 1;
    }

    public function clear(): string
    {
        $generation = bin2hex(random_bytes(16));
        $script = <<<'LUA'
local old=redis.call('GET',KEYS[1]); redis.call('SET',KEYS[1],ARGV[1]); if old then redis.call('RPUSH',KEYS[2],old) end; return ARGV[1]
LUA;
        $result = $this->redis->script($script, [$this->root . ':active', $this->root . ':retired'], [$generation]);
        if ($result !== $generation) {
            throw new CacheException('缓存代次切换没有确认');
        }
        return $generation;
    }

    /** 每次最多回收 limit 个索引或旧代标记，不扫描整个 Redis。 */
    public function collect(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('缓存回收批量必须为 1 至 1000');
        }
        $script = <<<'LUA'
local budget=tonumber(ARGV[1]); local removed=0
while budget>0 do
 local gen=redis.call('LINDEX',KEYS[1],0); if not gen then break end
 local prefix=KEYS[2]..':g:'..gen; local fields=redis.call('SPOP',prefix..':index',budget)
 for _,field in ipairs(fields) do removed=removed+redis.call('UNLINK',prefix..':v:'..field) end
 budget=budget-#fields
 if redis.call('SCARD',prefix..':index')==0 then redis.call('LPOP',KEYS[1]); redis.call('UNLINK',prefix..':index',prefix..':permanent'); budget=budget-1
 elseif #fields==0 then break end
end
return removed
LUA;
        return (int) $this->redis->script($script, [$this->root . ':retired', $this->root], [$limit]);
    }

    private function keys(array $keys): array
    {
        if (!array_is_list($keys) || count($keys) > 1000) {
            throw new InvalidArgumentException('缓存批次必须是最多 1000 个键的列表');
        }
        $hashes = [];
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '' || strlen($key) > 1024 || str_contains($key, "\0")) {
                throw new InvalidArgumentException('缓存键必须是非空且不超长的字符串');
            }
            $hashes[] = hash('sha256', $key);
        }
        return $hashes;
    }

    private function generation(string $generation): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $generation)) {
            throw new CacheException('缓存代次数据已损坏');
        }
    }
}
