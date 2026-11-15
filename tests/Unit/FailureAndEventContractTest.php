<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use Type\Core\BusinessEvents;
use Type\Core\EventDispatcher;
use Type\Core\Events;
use Type\Core\Http\HttpError;
use Type\Core\LifecycleEvent;
use Type\Runtime\FailureInterface;
use Type\Runtime\PublicFailureInterface;
use Type\Runtime\TaskException;
use Type\Validate\ValidationException;

/**
 * 验证异常公开契约和生命周期事件名称各自独立，避免将失败流程伪装成事件。
 * @internal
 * @coversNothing
 */
final class FailureAndEventContractTest extends TestCase
{
    public function testPublicFailuresExposeStableResponseContract(): void
    {
        $http = new HttpError(404, 'not_found');
        $validation = new ValidationException(['name' => ['required']]);

        self::assertInstanceOf(PublicFailureInterface::class, $http);
        self::assertSame(['error' => 'not_found'], $http->body());
        self::assertInstanceOf(PublicFailureInterface::class, $validation);
        self::assertSame('validation_failed', $validation->body()['error']);
        self::assertInstanceOf(FailureInterface::class, new TaskException('deadline_exceeded', 'expired'));
    }

    public function testLifecycleEventsRejectUndeclaredNames(): void
    {
        $events = new Events();
        $listener = new class implements \Type\Core\Listener {
            public function handle(string $event, \Type\Core\Configuration $configuration): void {}
        };
        $events->listen(LifecycleEvent::READY, $listener);
        $this->expectException(\InvalidArgumentException::class);
        $events->listen('error', $listener);
    }

    public function testBusinessEventsUseASeparateTypedDispatcherContract(): void
    {
        self::assertTrue(is_subclass_of(BusinessEvents::class, EventDispatcher::class));
        self::assertNotSame(LifecycleEvent::READY, 'business.event');
    }
}
