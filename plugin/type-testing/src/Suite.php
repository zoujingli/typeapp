<?php

declare(strict_types=1);

namespace Type\Testing;

/** 有名测试在公开接口处执行，失败继续收集并返回稳定退出码。 */
final class Suite
{
    private array $tests = [];
    private array $results = [];
    /** @param \Closure(): mixed $operation 零参数测试体，返回值忽略。 */
    public function test(string $name, \Closure $operation): Suite
    {
        if ($name === '' || strlen($name) > 200 || isset($this->tests[$name]) || count($this->tests) >= 10000) {
            throw new \InvalidArgumentException('测试名称重复或测试预算无效');
        }
        $this->tests[$name] = $operation;
        return $this;
    }
    /** 按登记顺序重跑全部测试并覆盖上次结果；全部通过返回 0，否则返回 1。 */
    public function run(): int
    {
        $this->results = [];
        $failed = 0;
        foreach ($this->tests as $name => $operation) {
            $started = hrtime(true);
            try {
                $operation();
                $result = ['name' => $name, 'passed' => true];
            } catch (\Throwable $error) {
                $failed++;
                $result = ['name' => $name, 'passed' => false, 'exception' => get_class($error), 'message' => $error->getMessage()];
            }
            $result['seconds'] = (hrtime(true) - $started) / 1000000000.0;
            $this->results[] = $result;
        }
        return $failed === 0 ? 0 : 1;
    }
    /**
     * 返回最近一次执行的结果，未运行时为空；耗时使用单调时钟并以秒记录。
     * @return list<array{name: string, passed: bool, seconds: float, exception?: class-string<\Throwable>, message?: string}>
     */
    public function results(): array
    {
        return $this->results;
    }
}
