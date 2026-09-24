<?php

declare(strict_types=1);

namespace Type\Cache;

use ReflectionReference;
use SplObjectStorage;
use Throwable;

/** 保留 PHP 类型的签名序列化；签名用于完整性校验，不提供数据加密。 */
final class SignedSerializer
{
    private string $secret;
    private array $classes;
    private string $policy;
    private int $limit;

    /**
     * 建立受信类型策略；密钥从运行配置传入，不能写入编译常量。
     *
     * @param list<class-string> $classes 已加载的受信对象类型，其序列化钩子属于应用代码。
     * @param int $maxBytes 含签名头的总载荷字节上限，范围 128 至 16 MiB。
     * @throws InvalidCacheArgument 密钥少于 32 字节、类型不存在或限制无效。
     */
    public function __construct(#[\SensitiveParameter] string $secret, array $classes = [], int $maxBytes = 1048576)
    {
        if (strlen($secret) < 32 || $maxBytes < 128 || $maxBytes > 16777216 || !array_is_list($classes)) {
            throw new InvalidCacheArgument('缓存签名密钥、类型映射或载荷限制无效');
        }
        foreach ($classes as $class) {
            if (!is_string($class) || $class === '' || !class_exists($class)) {
                throw new InvalidCacheArgument('受信任缓存对象类不存在');
            }
        }
        $classes = array_values(array_unique($classes));
        sort($classes);
        $this->secret = $secret;
        $this->classes = $classes;
        $this->limit = $maxBytes;
        $this->policy = hash('sha256', json_encode($classes, JSON_THROW_ON_ERROR));
    }

    /** 验证受信类型和资源边界后序列化，将命名空间、代次、键及完整载荷绑定到 HMAC。 */
    public function encode(mixed $value, string $namespace, string $generation, string $key): string
    {
        $references = [];
        $this->inspect($value, new SplObjectStorage(), $references, 0);
        try {
            $payload = serialize($value);
        } catch (Throwable $error) {
            throw new CacheException('缓存值不能序列化', 0, $error);
        }
        if (strlen($payload) > $this->limit - 136) {
            throw new CacheException('序列化缓存载荷超限');
        }
        (new SerializedPayload($payload, $this->classes))->verify();
        return "TSC1\n" . $this->policy . "\n" . $this->signature($payload, $namespace, $generation, $key) . "\n" . $payload;
    }

    /**
     * 先校验身份、签名与载荷结构，再调用受信类型的反序列化过程。
     *
     * @throws CacheException 载荷过大、被篡改、类型不符或解码失败。
     */
    public function decode(string $value, string $namespace, string $generation, string $key): mixed
    {
        if (strlen($value) > $this->limit) {
            throw new CacheException('序列化缓存载荷超限');
        }
        $parts = explode("\n", $value, 4);
        if (count($parts) !== 4 || $parts[0] !== 'TSC1' || $parts[1] !== $this->policy
            || !hash_equals($this->signature($parts[3], $namespace, $generation, $key), $parts[2])) {
            throw new CacheException('缓存格式、来源或完整性校验失败');
        }
        (new SerializedPayload($parts[3], $this->classes))->verify();
        try {
            $result = @unserialize($parts[3], ['allowed_classes' => $this->classes, 'max_depth' => 64]);
            if ($result === false && $parts[3] !== 'b:0;') {
                throw new CacheException('序列化缓存无法解码');
            }
            return $result;
        } catch (Throwable $error) {
            throw new CacheException('缓存对象解码失败', 0, $error);
        }
    }

    private function signature(string $payload, string $namespace, string $generation, string $key): string
    {
        return hash_hmac('sha256', $namespace . "\0" . $generation . "\0" . hash('sha256', $key) . "\0" . $this->policy . "\0" . $payload, $this->secret);
    }

    private function inspect(mixed &$value, SplObjectStorage $objects, array &$references, int $depth): void
    {
        if ($depth > 64) {
            throw new InvalidCacheArgument('缓存值深度超限');
        }
        if (is_resource($value) || gettype($value) === 'resource (closed)') {
            throw new InvalidCacheArgument('资源不能保存到缓存');
        }
        if (is_object($value)) {
            if ($objects->offsetExists($value)) {
                return;
            }
            if (!in_array(get_class($value), $this->classes, true)) {
                throw new InvalidCacheArgument('对象类型没有在受信任缓存映射中登记');
            }
            $objects->offsetSet($value);
            $properties = get_mangled_object_vars($value);
            foreach ($properties as &$property) {
                $this->inspect($property, $objects, $references, $depth + 1);
            }
            return;
        }
        if (is_array($value)) {
            foreach (array_keys($value) as $key) {
                $reference = ReflectionReference::fromArrayElement($value, $key);
                if ($reference !== null) {
                    $id = bin2hex($reference->getId());
                    if (isset($references[$id])) {
                        continue;
                    }
                    $references[$id] = true;
                }
                $this->inspect($value[$key], $objects, $references, $depth + 1);
            }
        }
    }
}
