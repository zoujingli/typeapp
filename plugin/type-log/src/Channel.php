<?php

declare(strict_types=1);

namespace Type\Log;

/** 将最低日志级别绑定到一个输出；多个通道可共享同一输出容量。 */
final class Channel
{
    private Output $output;
    private int $minimum;

    /**
     * 登记输出与最低 PSR-3 级别，不在构造时打开文件。
     *
     * @throws \Psr\Log\InvalidArgumentException 最低级别不属于 PSR-3。
     */
    public function __construct(Output $output, string $minimumLevel = 'debug')
    {
        $this->output = $output;
        $this->minimum = Level::weight($minimumLevel);
    }

    /** 返回实际输出实例；同实例的容量与写出计数由全部引用通道共享。 */
    public function output(): Output
    {
        return $this->output;
    }
    /** 按标准级别权重判断是否记录；不执行格式化或输出。 */
    public function accepts(int $weight): bool
    {
        return $weight >= $this->minimum;
    }
}
