<?php

declare(strict_types=1);

/** 被错误处理器间接持有，须在所属类元数据仍存活时释放。 */
final class EmbedShutdownValue
{
    public string $value = 'alive';
}

/** Zend 保留的方法闭包跨越 main() 返回，检查正常请求关闭的对象生命周期。 */
final class EmbedShutdownHandler
{
    public EmbedShutdownValue $child;

    /** 为最终析构保留带类型属性的嵌套对象。 */
    public function __construct()
    {
        $this->child = new EmbedShutdownValue();
    }

    /** 完整保留错误处理回调签名；此用例不改变错误策略。 */
    public function error(int $level, string $message, string $file, int $line): bool
    {
        return false;
    }

    /** 析构只执行一次，且此时仍能读取嵌套对象。 */
    public function __destruct()
    {
        echo 'destructor:', $this->child->value, "\n";
    }
}

/** 不主动恢复处理器，让 Zend 的请求关闭承担真实的最终释放。 */
function main(): void
{
    $handler = new EmbedShutdownHandler();
    set_error_handler($handler->error(...));
    echo "main\n";
    if (getenv('TYPE_TEST_EMBED_EXIT') === 'nested') {
        try {
            try {
                throw new InvalidArgumentException('rejected');
            } catch (InvalidArgumentException $error) {
                exit(7);
            } finally {
                echo "unexpected-finally\n";
            }
        } catch (Throwable $error) {
            echo "unexpected-catch\n";
            exit(70);
        }
    }
    if (getenv('TYPE_TEST_EMBED_EXIT') === '1') {
        exit(7);
    }
}
