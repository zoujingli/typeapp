<?php

declare(strict_types=1);

namespace TypeApp\AssemblyFixture;

use Type\Core\Command;
use Type\Core\Configuration;

/** 验证接口绑定保持显式。 */
interface Label
{
    public function text(): string;
}

/** 验证抽象绑定保持显式。 */
abstract class Prefix
{
    abstract public function text(): string;
}

/** 标量仅来自明确配置绑定。 */
final class Settings
{
    public function __construct(public string $name)
    {
    }
}

final class ApplicationPrefix extends Prefix
{
    public function text(): string
    {
        return '应用';
    }
}

final class ComponentPrefix extends Prefix
{
    public function text(): string
    {
        return '组件';
    }
}

final class Message implements Label
{
    public function __construct(private Settings $settings, private Prefix $prefix)
    {
    }

    public function text(): string
    {
        return $this->prefix->text() . ':' . $this->settings->name;
    }
}

/** 返回确定类型；所有服务依赖都来自类型化参数。 */
final class MessageFactory
{
    public static function create(Settings $settings, Prefix $prefix): Message
    {
        return new Message($settings, $prefix);
    }

    public static function unknown(Settings $settings)
    {
        return new ApplicationPrefix();
    }

    public static function hidden(): Message
    {
        global $message;
        return $message;
    }
}

final class Summary
{
    public function __construct(private Label $message)
    {
    }

    public function text(): string
    {
        return $this->message->text();
    }
}

/** 只声明此入口即可推导多层具名具体类。 */
final class ShowCommand implements Command
{
    public function __construct(private Summary $summary)
    {
    }

    public function run(Configuration $configuration, array $arguments): int
    {
        echo $this->summary->text() . PHP_EOL;
        return 0;
    }
}
