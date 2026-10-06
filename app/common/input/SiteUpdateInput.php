<?php

declare(strict_types=1);

namespace app\common\input;

use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidatedInput;
use Type\Core\Http\HttpError;

/** 站点更新的版本和变更对象；具体品牌、主题白名单由原 SiteSettings 继续拥有。 */
final class SiteUpdateInput implements ValidatedInput
{
    /** 只持有本次已验证对象，不提供运行配置或身份覆盖入口。 */
    public function __construct(public int $version, public array $changes)
    {
    }

    /** 正文只能包含 version 与 changes，嵌套业务规则保持在原服务。 */
    public static function schema(): Schema
    {
        return new Schema([
            'version' => Field::integer()->required()->range(1, PHP_INT_MAX)
                ->when(static fn (Input $input, string $scenario): bool => self::structure($input)),
            'changes' => (new Field('object'))->required(),
        ]);
    }

    /** 接受 JSON 对象的显式数组表示，缺失不转换为空更新。 */
    public static function fromData(Data $data): SiteUpdateInput
    {
        $changes = $data->get('changes');
        return new SiteUpdateInput($data->get('version'), $changes instanceof \stdClass ? get_object_vars($changes) : $changes);
    }

    /** 保留站点表单既有错误码，版本及变更类型仍由同一 Schema 验证。 */
    private static function structure(Input $input): bool
    {
        InputFields::only($input, 'body', ['version', 'changes'], 'site_settings_input_invalid');
        $body = $input->source('body');
        if (!is_int($body['version'] ?? null) || $body['version'] < 1
            || !(($body['changes'] ?? null) instanceof \stdClass || (is_array($body['changes'] ?? null) && !array_is_list($body['changes'])))) {
            throw new HttpError(422, 'site_settings_input_invalid');
        }
        return true;
    }
}
