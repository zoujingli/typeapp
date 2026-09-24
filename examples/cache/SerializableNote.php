<?php

declare(strict_types=1);

namespace TypeApp\CacheExample;

/** PSR 缓存对象序列化示例，以唤醒计数观察签名验证与恢复顺序。 */
final class SerializableNote
{
    private string $text;
    public static int $awakened = 0;
    /** 保存演练文本，不触发序列化或恢复计数。 */
    public function __construct(string $text)
    {
        $this->text = $text;
    }
    /** 返回已恢复的文本，供对象往返检查。 */
    public function text(): string
    {
        return $this->text;
    }
    /**
     * 仅序列化明确文本字段。
     *
     * @return array{text: string}
     */
    public function __serialize(): array
    {
        return ['text' => $this->text];
    }
    /**
     * 记录对象唤醒并恢复文本，供签名拒绝前不调用对象代码的断言。
     *
     * @param array{text: string} $data 本类生成的序列化字段。
     */
    public function __unserialize(array $data): void
    {
        self::$awakened++;
        $this->text = $data['text'];
    }
}
