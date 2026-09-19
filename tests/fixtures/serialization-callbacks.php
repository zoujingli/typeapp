<?php

declare(strict_types=1);

/** 对象钩子从 Zend 返回 AOT 调用者时，必须保留原始异常和捕获范围。 */
class SerializationRejection extends RuntimeException
{
    public function __construct(string $message) { parent::__construct($message); }
}

class RejectedSerialization implements JsonSerializable
{
    public function jsonSerialize(): mixed { throw new SerializationRejection('json-rejected'); }
    public function __serialize(): array { throw new SerializationRejection('serialize-rejected'); }
}

final class DerivedSerialization extends RejectedSerialization
{
}

final class HookRead
{
    public function __unserialize(array $values): void { throw new SerializationRejection('unserialize-rejected'); }
}

final class HookWake
{
    public function __wakeup(): void { throw new SerializationRejection('wakeup-rejected'); }
}

function expectSerializationRejection(string $mode, string $message): void
{
    $caught = false;
    $value = str_starts_with($mode, 'derived-') ? new DerivedSerialization() : new RejectedSerialization();
    try {
        switch ($mode) {
            case 'json': json_encode($value, JSON_THROW_ON_ERROR); break;
            case 'serialize': serialize($value); break;
            case 'derived-json': json_encode($value, JSON_THROW_ON_ERROR); break;
            case 'derived-serialize': serialize($value); break;
            case 'unserialize': unserialize('O:8:"HookRead":0:{}'); break;
            case 'wakeup': unserialize('O:8:"HookWake":0:{}'); break;
            case 'universal-json':
                $jsonValues = [new RejectedSerialization()];
                $jsonValues->jsonEncode(JSON_THROW_ON_ERROR);
                break;
            case 'universal-serialize':
                $serializationValues = [new RejectedSerialization()];
                $serializationValues->serialize();
                break;
            case 'universal-unserialize':
                $encodedValue = 'O:8:"HookRead":0:{}';
                $encodedValue->unserialize();
                break;
            default: throw new RuntimeException('回归场景名称无效');
        }
    } catch (SerializationRejection $error) {
        $caught = $error->getMessage() === $message;
    }
    if (!$caught) { throw new RuntimeException('原生序列化回调没有传播原始异常：' . $message); }
}

function main(): void
{
    // 连续调用同时检查异常状态已经清理；返回值故意不使用，异常副作用仍须保留。
    for ($round = 0; $round < 3; $round++) {
        expectSerializationRejection('json', 'json-rejected');
        expectSerializationRejection('serialize', 'serialize-rejected');
        expectSerializationRejection('derived-json', 'json-rejected');
        expectSerializationRejection('derived-serialize', 'serialize-rejected');
        expectSerializationRejection('unserialize', 'unserialize-rejected');
        expectSerializationRejection('wakeup', 'wakeup-rejected');
        expectSerializationRejection('universal-json', 'json-rejected');
        expectSerializationRejection('universal-serialize', 'serialize-rejected');
        expectSerializationRejection('universal-unserialize', 'unserialize-rejected');
        // 成功路径必须继续产生实际内容。
        $encoded = json_encode(['value' => 42], JSON_THROW_ON_ERROR);
        $serialized = serialize(['value' => 42]);
        $decoded = unserialize($serialized);
        if ($encoded !== '{"value":42}' || $decoded !== ['value' => 42]) {
            throw new RuntimeException('异常捕获后序列化状态没有恢复');
        }
    }
    echo "原生 JSON、序列化与反序列化的 27 次回调异常及成功路径检查通过。\n";
}
