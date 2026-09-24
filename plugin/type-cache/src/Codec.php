<?php

declare(strict_types=1);

namespace Type\Cache;

/** 业务值与缓存载荷的显式转换协议；不根据载荷自动加载类。 */
interface Codec
{
    /** 返回稳定的格式身份；数据契约改变时应使用新身份。 */
    public function format(): string;
    /** 将业务值转换为载荷；类型不满足声明时应抛出异常。 */
    public function encode(mixed $value): string;
    /** 从载荷恢复业务值；损坏或不兼容内容应抛错，交由缓存读路径判定未命中。 */
    public function decode(string $payload): mixed;
}
