<?php

declare(strict_types=1);

namespace Type\Runtime;

/** 应用版本、数据库、消息与缓存协议分开声明；不替调用者猜测外部状态。 */
final class ReleaseCompatibility
{
    private string $version;
    private array $capabilities;
    /**
     * 建立应用显式支持的协议版本集合，不读取数据库或消息系统推断兼容性。
     * @param array<string, array<string, list<int>>> $capabilities schema、messages 和 cache 三类协议。
     * @throws \InvalidArgumentException 应用版本、资源名或版本集合无效。
     */
    public function __construct(string $version, array $capabilities)
    {
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D', $version) || strlen($version) > 100) {
            throw new \InvalidArgumentException('应用版本格式无效');
        }
        $this->version = $version;
        $this->capabilities = ['schema' => [], 'messages' => [], 'cache' => []];
        foreach ($capabilities as $kind => $resources) {
            if (!array_key_exists($kind, $this->capabilities) || !is_array($resources) || count($resources) > 1000) {
                throw new \InvalidArgumentException('发布能力类别无效');
            }
            foreach ($resources as $name => $versions) {
                if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/D', $name) || !is_array($versions) || !array_is_list($versions)
                    || $versions === [] || count($versions) > 100 || count(array_unique($versions, SORT_REGULAR)) !== count($versions)) {
                    throw new \InvalidArgumentException('资源版本声明无效');
                }
                foreach ($versions as $number) {
                    if (!is_int($number) || $number < 1 || $number > 2147483647) {
                        throw new \InvalidArgumentException('协议版本必须是明确正整数');
                    }
                }
                sort($versions);
                $this->capabilities[$kind][$name] = $versions;
            }
        }
    }
    /** @throws CompatibilityException 数据库协议版本未在当前应用的支持集合中。 */
    public function assertSchema(string $name, int $version): void
    {
        if (!$this->supports('schema', $name, $version)) {
            throw new CompatibilityException('schema_incompatible', '应用 ' . $this->version . ' 不支持当前数据库协议：' . $name . ' v' . $version);
        }
    }
    /** @throws CompatibilityException 待消费或恢复的消息协议版本不受支持。 */
    public function assertConsumer(string $type, int $version): void
    {
        if (!$this->supports('messages', $type, $version)) {
            throw new CompatibilityException('message_incompatible', '消费者 ' . $this->version . ' 不支持待投递或待恢复的消息：' . $type . ' v' . $version);
        }
    }
    /** 检查缓存协议是否可读取；false 由调用方按缓存未命中处理。 */
    public function acceptsCache(string $namespace, int $version): bool
    {
        return $this->supports('cache', $namespace, $version);
    }
    /**
     * 返回可随发布产物记录的兼容声明快照。
     * @return array{version: string, capabilities: array<string, array<string, list<int>>>}
     */
    public function metadata(): array
    {
        return ['version' => $this->version, 'capabilities' => $this->capabilities];
    }
    private function supports(string $kind, string $name, int $version): bool
    {
        return in_array($version, $this->capabilities[$kind][$name] ?? [], true);
    }
}
