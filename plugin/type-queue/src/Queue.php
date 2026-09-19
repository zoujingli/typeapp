<?php

declare(strict_types=1);

namespace Type\Queue;

use Type\Redis\RedisConnection;
use Type\Redis\ScriptGuard;

final class Queue
{
    private RedisConnection $redis;
    private string $root;
    private string $group;
    private int $leaseMilliseconds;
    private int $capacity;
    private int $retention;
    private array $counts = ['publish_rejected' => 0, 'storage_failures' => 0, 'lease_rejected' => 0];
    public function __construct(RedisConnection $redis, string $application, string $name = 'default', int $leaseMilliseconds = 30000, int $capacity = 10000, int $retentionSeconds = 604800)
    {
        if ($application === '' || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) || $leaseMilliseconds < 10 || $leaseMilliseconds > 3600000
            || $capacity < 1 || $capacity > 1000000 || $retentionSeconds < 1 || $retentionSeconds > 31536000) {
            throw new QueueException('invalid_configuration', '队列身份、租约、容量或保留期无效');
        }
        $this->redis = $redis;
        $this->root = 'type:queue:{' . hash('sha256', $application . "\0" . $name) . '}';
        $this->group = 'workers';
        $this->leaseMilliseconds = $leaseMilliseconds;
        $this->capacity = $capacity;
        $this->retention = $retentionSeconds;
    }

    public function publish(Message $message, ?ScriptGuard $guard = null): string
    {
        $encoded = $message->encode();
        $script = <<<'LUA'
if redis.call('XLEN',KEYS[1])+redis.call('HLEN',KEYS[2])+redis.call('HLEN',KEYS[3])>=tonumber(ARGV[1]) then return redis.error_reply('queue capacity exceeded') end
local time=redis.call('TIME'); local now=time[1]*1000+math.floor(time[2]/1000)
return redis.call('XADD',KEYS[1],'*','message',ARGV[2],'attempt','1','enqueued_at',now)
LUA;
        $keys = [$this->root . ':stream', $this->root . ':delayed-data', $this->root . ':quarantine-data'];
        $arguments = [$this->capacity, $encoded];
        try {
            $receipt = $guard === null ? $this->script($script, $keys, $arguments) : $guard->execute($this->redis, $script, $keys, $arguments);
        } catch (\Throwable $error) {
            $this->counts['publish_rejected']++;
            throw $error;
        }
        if (!is_string($receipt) || !preg_match('/^[0-9]+-[0-9]+$/D', $receipt)) {
            throw new QueueException('publish_unknown', '投递没有返回可靠的 Stream 身份，需要核对实际消息');
        }
        return $receipt;
    }

    public function identity(): string
    {
        return $this->root;
    }

    public function publishDelayed(Message $message, int $delayMilliseconds): string
    {
        if ($delayMilliseconds < 0 || $delayMilliseconds > 31536000000) {
            throw new QueueException('invalid_delay', '任务延迟时间无效');
        }
        if ($delayMilliseconds === 0) {
            return $this->publish($message);
        }
        $id = 'scheduled-' . bin2hex(random_bytes(16));
        $script = <<<'LUA'
if redis.call('XLEN',KEYS[1])+redis.call('HLEN',KEYS[2])+redis.call('HLEN',KEYS[3])>=tonumber(ARGV[1]) then return redis.error_reply('queue capacity exceeded') end
local time=redis.call('TIME'); local due=time[1]*1000+math.floor(time[2]/1000)+tonumber(ARGV[4])
redis.call('ZADD',KEYS[4],due,ARGV[2]); redis.call('HSET',KEYS[2],ARGV[2],cjson.encode({message=ARGV[3],attempt=1,reason='',due=due,enqueued_at=due-tonumber(ARGV[4])}))
return ARGV[2]
LUA;
        try {
            return (string) $this->script(
                $script,
                [$this->root . ':stream', $this->root . ':delayed-data', $this->root . ':quarantine-data', $this->root . ':delayed'],
                [$this->capacity, $id, $message->encode(), $delayMilliseconds]
            );
        } catch (\Throwable $error) {
            $this->counts['publish_rejected']++;
            throw $error;
        }
    }

    public function promote(int $limit = 100): int
    {
        $this->limit($limit);
        $script = <<<'LUA'
local time=redis.call('TIME'); local now=time[1]*1000+math.floor(time[2]/1000); local count=0
local due=redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',now,'LIMIT',0,ARGV[1])
for _,id in ipairs(due) do
 local payload=redis.call('HGET',KEYS[2],id)
 if payload then local item=cjson.decode(payload); redis.call('XADD',KEYS[3],'*','message',item.message,'attempt',item.attempt,'enqueued_at',item.enqueued_at or now); count=count+1 end
 redis.call('HDEL',KEYS[2],id); redis.call('ZREM',KEYS[1],id)
end
return count
LUA;
        return (int) $this->script($script, [$this->root . ':delayed', $this->root . ':delayed-data', $this->root . ':stream'], [$limit]);
    }

    public function reserve(string $consumer): ?Reservation
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $consumer)) {
            throw new QueueException('invalid_consumer', '消费者身份无效');
        }
        $token = bin2hex(random_bytes(16));
        $script = <<<'LUA'
local group=redis.pcall('XGROUP','CREATE',KEYS[1],ARGV[1],'0','MKSTREAM')
if type(group)=='table' and group.err and not string.find(group.err,'BUSYGROUP',1,true) then return group end
local rows=redis.call('XREADGROUP','GROUP',ARGV[1],ARGV[2],'COUNT','1','STREAMS',KEYS[1],'>')
if not rows then return false end
local entry=rows[1][2][1]; local now=redis.call('TIME'); local expires=now[1]*1000+math.floor(now[2]/1000)+tonumber(ARGV[4])
redis.call('HSET',KEYS[2],entry[1],ARGV[2]..'|'..ARGV[3]..'|'..expires)
return {entry[1],entry[2],1,now[1]*1000+math.floor(now[2]/1000)}
LUA;
        $result = $this->script($script, [$this->root . ':stream', $this->root . ':leases'], [$this->group, $consumer, $token, $this->leaseMilliseconds]);
        return $this->reservation($result, $consumer, $token);
    }

    public function reclaim(string $consumer): ?Reservation
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$/D', $consumer)) {
            throw new QueueException('invalid_consumer', '消费者身份无效');
        }
        $token = bin2hex(random_bytes(16));
        $script = <<<'LUA'
local group=redis.pcall('XGROUP','CREATE',KEYS[1],ARGV[1],'0','MKSTREAM')
if type(group)=='table' and group.err and not string.find(group.err,'BUSYGROUP',1,true) then return group end
local now=redis.call('TIME'); local ms=now[1]*1000+math.floor(now[2]/1000)
local rows=redis.call('XPENDING',KEYS[1],ARGV[1],'IDLE',ARGV[4],'-','+',100)
for _,row in ipairs(rows) do
 local old=redis.call('HGET',KEYS[2],row[1]); local expires=old and string.match(old,'|(%d+)$')
 if not expires or tonumber(expires)<=ms then
  local entries=redis.call('XCLAIM',KEYS[1],ARGV[1],ARGV[2],0,row[1])
  if #entries>0 then redis.call('HSET',KEYS[2],row[1],ARGV[2]..'|'..ARGV[3]..'|'..(ms+tonumber(ARGV[4]))); return {entries[1][1],entries[1][2],row[4]+1,ms}
  else redis.call('HDEL',KEYS[2],row[1]) end
 end
end
return false
LUA;
        $result = $this->script($script, [$this->root . ':stream', $this->root . ':leases'], [$this->group, $consumer, $token, $this->leaseMilliseconds]);
        return $this->reservation($result, $consumer, $token);
    }

    private function reservation(mixed $result, string $consumer, string $token): ?Reservation
    {
        if ($result === false || $result === null) {
            return null;
        }
        if (!is_array($result) || count($result) !== 4 || !is_array($result[1])) {
            throw new QueueException('invalid_stream', 'Streams 返回格式不兼容');
        }
        $fields = [];
        for ($index = 0; $index < count($result[1]); $index += 2) {
            $fields[$result[1][$index]] = $result[1][$index + 1];
        }
        $attempt = filter_var($fields['attempt'] ?? '1', FILTER_VALIDATE_INT);
        $encoded = (string) ($fields['message'] ?? '');
        try {
            if (!is_int($attempt) || $attempt < 1 || $attempt > 100000) {
                throw new QueueException('invalid_attempt', '任务尝试次数无效');
            }
            $message = Message::decode($encoded);
        } catch (QueueException $error) {
            if (!$this->transfer((string) $result[0], $consumer, $token, $encoded, 1, $error->errorCode(), null)) {
                throw new QueueException('lease_lost', '隔离非法载荷时租约失效');
            }
            throw new QueueException('quarantined', '非法任务载荷已隔离');
        }
        $enqueuedAt = (int) ($fields['enqueued_at'] ?? explode('-', (string) $result[0])[0]);
        if ($enqueuedAt < 1) {
            $enqueuedAt = (int) explode('-', (string) $result[0])[0];
        }
        return new Reservation($this, (string) $result[0], $consumer, $token, $message, $attempt + (int) $result[2] - 1, $enqueuedAt, max(0, (int) $result[3] - $enqueuedAt));
    }

    public function owns(Reservation $reservation): bool
    {
        return $this->verify($reservation, 'check');
    }

    public function acknowledge(Reservation $reservation): bool
    {
        return $this->verify($reservation, 'ack');
    }

    public function renew(Reservation $reservation): bool
    {
        return $this->verify($reservation, 'renew');
    }

    /** 只对同一 Redis 中的受管副作用提供原子 fencing，外部数据库仍需自己的幂等/版本约束。 */
    public function effect(Reservation $reservation, string $script, array $keys, array $arguments): mixed
    {
        if ($script === '' || strlen($script) > 65536 || !array_is_list($keys) || !array_is_list($arguments)) {
            throw new QueueException('invalid_effect', '任务副作用脚本无效');
        }
        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                throw new QueueException('invalid_effect', '副作用键无效');
            }
        }
        $guard = $this->guard();
        $guard .= "\nlocal effectKeys={}; for i=3,#KEYS do effectKeys[#effectKeys+1]=KEYS[i] end\nlocal effectArgs={}; for i=5,#ARGV do effectArgs[#effectArgs+1]=ARGV[i] end\n";
        $guard .= "local function effect(KEYS,ARGV)\n" . $script . "\nend\nreturn {1,effect(effectKeys,effectArgs)}";
        $result = $this->script(
            $guard,
            array_merge([$this->root . ':stream', $this->root . ':leases'], $keys),
            array_merge([$this->group, $reservation->streamId(), $reservation->consumer(), $reservation->token()], $arguments)
        );
        if (!is_array($result) || ($result[0] ?? null) !== 1) {
            $this->counts['lease_rejected']++;
            throw new QueueException('lease_lost', '旧任务租约不能继续受管副作用');
        }
        return $result[1] ?? null;
    }

    public function statistics(): array
    {
        $result = $this->script(
            <<<'LUA'
local time=redis.call('TIME'); local now=time[1]*1000+math.floor(time[2]/1000); local first=redis.call('XRANGE',KEYS[1],'-','+','COUNT',1)
local age=0; if #first>0 then age=math.max(0,now-tonumber(string.match(first[1][1],'^(%d+)'))) end
return {redis.call('XLEN',KEYS[1]),redis.call('HLEN',KEYS[2]),redis.call('HLEN',KEYS[3]),redis.call('HLEN',KEYS[4]),age}
LUA,
            [$this->root . ':stream', $this->root . ':leases', $this->root . ':delayed-data', $this->root . ':quarantine-data']
        );
        return ['messages' => (int) $result[0], 'leased' => (int) $result[1], 'delayed' => (int) $result[2], 'quarantined' => (int) $result[3],
            'capacity' => $this->capacity, 'backlog' => (int) $result[0] + (int) $result[2], 'oldest_stream_age_ms' => (int) $result[4]] + $this->counts;
    }

    public function counters(): array
    {
        return $this->counts;
    }

    public function fail(Reservation $reservation, string $reason, ?int $delayMilliseconds): bool
    {
        return $this->transfer(
            $reservation->streamId(),
            $reservation->consumer(),
            $reservation->token(),
            $reservation->message()->encode(),
            $reservation->attempt(),
            $reason,
            $delayMilliseconds,
            $reservation->enqueuedAt()
        );
    }

    private function transfer(string $id, string $consumer, string $token, string $message, int $attempt, string $reason, ?int $delay, int $enqueuedAt = 0): bool
    {
        if ($reason === '' || strlen($reason) > 2000 || ($delay !== null && ($delay < 0 || $delay > 86400000))) {
            throw new QueueException('invalid_transfer', '任务转移原因或延迟无效');
        }
        $script = $this->guard() . <<<'LUA'

local milliseconds=now[1]*1000+math.floor(now[2]/1000)
local due=milliseconds+tonumber(ARGV[8]); local attempt=tonumber(ARGV[6])
if ARGV[9]=='retry' then attempt=attempt+1 end
local record=cjson.encode({message=ARGV[5],attempt=attempt,reason=ARGV[7],due=due,enqueued_at=tonumber(ARGV[10])})
redis.call('ZADD',KEYS[3],due,ARGV[2]); redis.call('HSET',KEYS[4],ARGV[2],record)
redis.call('XACK',KEYS[1],ARGV[1],ARGV[2]); redis.call('XDEL',KEYS[1],ARGV[2]); redis.call('HDEL',KEYS[2],ARGV[2]); return 1
LUA;
        $destination = $delay === null ? 'quarantine' : 'delayed';
        return $this->script(
            $script,
            [$this->root . ':stream', $this->root . ':leases', $this->root . ':' . $destination, $this->root . ':' . $destination . '-data'],
            [$this->group, $id, $consumer, $token, $message, $attempt, $reason, $delay ?? $this->retention * 1000, $delay === null ? 'quarantine' : 'retry', $enqueuedAt]
        ) === 1;
    }

    public function quarantined(int $limit = 100): array
    {
        $this->limit($limit);
        $script = <<<'LUA'
local time=redis.call('TIME'); local out={}; for _,id in ipairs(redis.call('ZRANGEBYSCORE',KEYS[1],time[1]*1000+math.floor(time[2]/1000),'+inf','LIMIT',0,ARGV[1])) do
 local value=redis.call('HGET',KEYS[2],id); if value then out[#out+1]={id,value} end end; return out
LUA;
        $result = [];
        foreach ($this->script($script, [$this->root . ':quarantine', $this->root . ':quarantine-data'], [$limit]) as $row) {
            $result[] = ['receipt' => $row[0]] + json_decode($row[1], true, 32, JSON_THROW_ON_ERROR);
        }
        return $result;
    }

    public function replay(string $receipt): string
    {
        if (!preg_match('/^[0-9]+-[0-9]+$/D', $receipt)) {
            throw new QueueException('invalid_receipt', '隔离记录 ID 无效');
        }
        $script = <<<'LUA'
local payload=redis.call('HGET',KEYS[1],ARGV[1]); if not payload then return false end
local record=cjson.decode(payload); local time=redis.call('TIME'); if record.due<=time[1]*1000+math.floor(time[2]/1000) then return false end
local decoded=cjson.decode(record.message); if not decoded.id then return redis.error_reply('invalid quarantined message') end
local id=redis.call('XADD',KEYS[3],'*','message',record.message,'attempt',1,'enqueued_at',record.enqueued_at or time[1]*1000+math.floor(time[2]/1000)); redis.call('HDEL',KEYS[1],ARGV[1]); redis.call('ZREM',KEYS[2],ARGV[1]); return id
LUA;
        $result = $this->script($script, [$this->root . ':quarantine-data', $this->root . ':quarantine', $this->root . ':stream'], [$receipt]);
        if (!is_string($result)) {
            throw new QueueException('quarantine_missing', '隔离记录不存在或已过保留期');
        }
        return $result;
    }

    public function collect(int $limit = 100): int
    {
        $this->limit($limit);
        $script = <<<'LUA'
local time=redis.call('TIME'); local ids=redis.call('ZRANGEBYSCORE',KEYS[1],'-inf',time[1]*1000+math.floor(time[2]/1000),'LIMIT',0,ARGV[1])
for _,id in ipairs(ids) do redis.call('HDEL',KEYS[2],id); redis.call('ZREM',KEYS[1],id) end; return #ids
LUA;
        return (int) $this->script($script, [$this->root . ':quarantine', $this->root . ':quarantine-data'], [$limit]);
    }

    private function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 1000) {
            throw new QueueException('invalid_limit', '队列状态批量必须为 1 至 1000');
        }
    }

    private function verify(Reservation $reservation, string $action): bool
    {
        $script = $this->guard() . <<<'LUA'

if ARGV[5]=='ack' then redis.call('XACK',KEYS[1],ARGV[1],ARGV[2]); redis.call('XDEL',KEYS[1],ARGV[2]); redis.call('HDEL',KEYS[2],ARGV[2])
elseif ARGV[5]=='renew' then
 redis.call('XCLAIM',KEYS[1],ARGV[1],ARGV[3],0,ARGV[2],'JUSTID')
 redis.call('HSET',KEYS[2],ARGV[2],ARGV[3]..'|'..ARGV[4]..'|'..(now[1]*1000+math.floor(now[2]/1000)+tonumber(ARGV[6])))
end
return 1
LUA;
        $valid = $this->script(
            $script,
            [$this->root . ':stream', $this->root . ':leases'],
            [$this->group, $reservation->streamId(), $reservation->consumer(), $reservation->token(), $action, $this->leaseMilliseconds]
        ) === 1;
        if (!$valid) {
            $this->counts['lease_rejected']++;
        }
        return $valid;
    }

    private function script(string $script, array $keys, array $arguments = []): mixed
    {
        try {
            return $this->redis->script($script, $keys, $arguments);
        } catch (\Throwable $error) {
            $this->counts['storage_failures']++;
            throw $error;
        }
    }

    private function guard(): string
    {
        return <<<'LUA'
local lease=redis.call('HGET',KEYS[2],ARGV[2]); if not lease then return 0 end
local owner,token,expires=string.match(lease,'^([^|]+)|([^|]+)|(%d+)$'); local now=redis.call('TIME')
if owner~=ARGV[3] or token~=ARGV[4] or tonumber(expires)<=now[1]*1000+math.floor(now[2]/1000) then return 0 end
local pending=redis.call('XPENDING',KEYS[1],ARGV[1],ARGV[2],ARGV[2],1)
if #pending~=1 or pending[1][2]~=ARGV[3] then return 0 end
LUA;
    }
}
