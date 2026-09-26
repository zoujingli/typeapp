<?php

declare(strict_types=1);

namespace app\common\service;

/** 可向运维显示的前端安装错误；消息不包含秘密或本机文件路径。 */
final class FrontendException extends \RuntimeException
{
}
