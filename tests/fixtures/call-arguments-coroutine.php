<?php

declare(strict_types=1);

/** 两个同时挂起的调用各自保留实参，内层闭包不能覆盖外层调用帧。 */
final class CoroutineArguments
{
    public function __construct(private Swoole\Coroutine\Channel $entered, private Swoole\Coroutine\Channel $release)
    {
    }

    public function inspect(string $id): array
    {
        $before = func_get_args();
        $this->entered->push($id);
        if ($this->release->pop(3.0) !== true) {
            throw new RuntimeException('等待另一协程进入超时');
        }
        $callback = static function (string $inner): array {
            Swoole\Coroutine::sleep(0.001);
            return [func_num_args(), func_get_args()];
        };
        $nested = $callback($id, 'inner-extra');
        return ['id' => $id, 'before' => $before, 'after' => func_get_args(), 'count' => func_num_args(), 'last' => func_get_arg(2), 'nested' => $nested];
    }
}

/** 用通道建立真实交错顺序，不依据固定等待猜测另一协程已经开始。 */
function main(): void
{
    Swoole\Coroutine\run(static function (): void {
        $entered = new Swoole\Coroutine\Channel(2);
        $release = new Swoole\Coroutine\Channel(2);
        $done = new Swoole\Coroutine\Channel(2);
        $probe = new CoroutineArguments($entered, $release);
        Swoole\Coroutine::create(static function () use ($probe, $done): void {
            $method = 'inspect';
            $done->push($probe->{$method}('left', ['token' => 'left'], null));
        });
        Swoole\Coroutine::create(static function () use ($probe, $done): void {
            $method = 'inspect';
            $done->push($probe->{$method}('right', ['token' => 'right'], 'right-frame'));
        });
        if ($entered->pop(3.0) === false || $entered->pop(3.0) === false) {
            throw new RuntimeException('协程没有同时进入');
        }
        $release->push(true);
        $release->push(true);
        $first = $done->pop(3.0);
        $second = $done->pop(3.0);
        if (!is_array($first) || !is_array($second)) {
            throw new RuntimeException('协程未在期限内返回');
        }
        $results = [$first['id'] => $first, $second['id'] => $second];
        foreach (['left', 'right'] as $id) {
            $expected = [$id, ['token' => $id], $id === 'left' ? null : 'right-frame'];
            if ($results[$id]['before'] !== $expected || $results[$id]['after'] !== $expected
                || $results[$id]['count'] !== 3 || $results[$id]['last'] !== $expected[2]
                || $results[$id]['nested'] !== [2, [$id, 'inner-extra']]) {
                throw new RuntimeException('协程或内层闭包之间泄漏了调用参数');
            }
        }
        echo json_encode(['ok' => true, 'checks' => ['two_suspended_coroutines', 'own_argument_values', 'nested_closure', 'resume_original_frame']], JSON_THROW_ON_ERROR), "\n";
    });
}
