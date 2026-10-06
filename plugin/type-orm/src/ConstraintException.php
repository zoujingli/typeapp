<?php

declare(strict_types=1);

namespace Type\Orm;

use PDOException;

/** 数据库确认的约束失败；无法确认目标时保留空身份，不允许据此恢复竞争。 */
final class ConstraintException extends DatabaseException
{
    private string $kind;
    private string $constraint;
    private array $columns;
    private string $sqlState;
    private int $vendorCode;

    /** @internal SQLSTATE 和驱动码决定类别，文本只用于提取服务端报告的目标。 */
    public function __construct(string $driver, PDOException $error)
    {
        $info = $error->errorInfo ?? [];
        $this->sqlState = (string) ($info[0] ?? $error->getCode());
        $this->vendorCode = (int) ($info[1] ?? 0);
        $message = (string) ($info[2] ?? '');
        $this->kind = 'other';
        $this->constraint = '';
        $this->columns = [];
        $match = [];
        if (($driver === 'pgsql' && $this->sqlState === '23505')
            || ($driver === 'mysql' && $this->sqlState === '23000' && $this->vendorCode === 1062)
            || ($driver === 'sqlite' && $this->sqlState === '23000' && ($this->vendorCode & 255) === 19
                && preg_match('/^UNIQUE constraint failed: (.+)$/D', $message, $match) === 1)) {
            $this->kind = 'unique';
            if ($driver === 'sqlite') {
                $this->columns = explode(', ', $match[1]);
            } elseif ($driver === 'pgsql' && preg_match('/unique constraint "([^"]+)"/', $message, $match) === 1) {
                $this->constraint = $match[1];
            } elseif ($driver === 'mysql' && preg_match("/for key ['`]([^'`]+)['`]/", $message, $match) === 1) {
                $parts = explode('.', $match[1]);
                $this->constraint = $parts[count($parts) - 1];
            }
        } elseif (($driver === 'pgsql' && $this->sqlState === '23503')
            || ($driver === 'mysql' && in_array($this->vendorCode, [1451, 1452], true))
            || ($driver === 'sqlite' && ($this->vendorCode & 255) === 19 && $message === 'FOREIGN KEY constraint failed')) {
            $this->kind = 'foreign_key';
        }
        parent::__construct('数据库约束失败，SQLSTATE：' . $this->sqlState . '，类别：' . $this->kind, 0, $error);
    }

    /** 返回 unique、foreign_key 或 other；other 不表示可以重试。 */
    public function kind(): string
    {
        return $this->kind;
    }

    /** 保留驱动提供的 SQLSTATE，不从人类消息猜测约束类别。 */
    public function sqlState(): string
    {
        return $this->sqlState;
    }

    /** 保留数据库驱动错误码，SQLite 可以含扩展码。 */
    public function vendorCode(): int
    {
        return $this->vendorCode;
    }

    /** @internal 仅接受服务端明确报告的索引名，或 SQLite 完整列身份。 */
    public function matches(string $table, array $index): bool
    {
        if ($this->kind !== 'unique') {
            return false;
        }
        if ($this->constraint !== '') {
            return $this->constraint === $index['name'];
        }
        $parts = explode('.', $table);
        $table = $parts[count($parts) - 1];
        $columns = [];
        foreach ($index['columns'] as $column) {
            $columns[] = $table . '.' . $column;
        }
        return $this->columns !== [] && $this->columns === $columns;
    }
}
