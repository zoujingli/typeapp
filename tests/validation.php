<?php

declare(strict_types=1);

require __DIR__ . '/support.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Type\Validate\Data;
use Type\Validate\Field;
use Type\Validate\Input;
use Type\Validate\Schema;
use Type\Validate\ValidationException;

$root = dirname(__DIR__);
if (isset($argv[1])) {
    $command = nativeCommand($argv[1]);
} else {
    $command = [PHP_BINARY, '-r', 'require $argv[1]."/vendor/autoload.php"; require $argv[1]."/examples/validation/UserInput.php"; require $argv[1]."/examples/validation-command.php"; main($argc-1, array_slice($argv, 1));', $root];
}
$cases = [
    ['{"name":" 开发者 ","age":32,"profile":{"city":"杭州"},"tags":["PHP"],"unknown":"忽略"}', 'page=2&newsletter=false', '', '', 0,
        ['data' => ['name' => '开发者', 'age' => 32, 'profile' => ['city' => '杭州'], 'tags' => ['PHP'], 'page' => 2, 'newsletter' => false]]],
    ['{"email":null}', '', 'patch', '', 0, ['data' => ['email' => null]]],
    ['{"name":"","age":"32"}', '', '', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['name' => ['length'], 'age' => ['type_integer']]]],
    ['{}', '', '', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['name' => ['required'], 'age' => ['required']]]],
    ['{"name":null}', '', 'patch', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['name' => ['null_not_allowed']]]],
    ['{"profile":[]}', '', 'patch', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['profile' => ['type_object']]]],
    ['{"profile":{"city":42}}', '', 'patch', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['profile.city' => ['type_string']]]],
    ['{"role":"editor"}', '', '', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['name' => ['required'], 'age' => ['required'], 'code' => ['required']]]],
    ['{"role":"admin","email":"bad","slug":"admin"}', 'page=101', 'patch', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['email' => ['email'], 'role' => ['enum'], 'page' => ['range'], 'slug' => ['reserved']]]],
    ['{"name":"测试","age":20}', '', '', 'register', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['password' => ['required']]]],
    ['{broken', '', '', '', 65, ['status' => 400, 'error' => 'invalid_json', 'fields' => ['body' => ['invalid_json']]]],
    ['[]', '', '', '', 65, ['status' => 400, 'error' => 'invalid_json', 'fields' => ['body' => ['object_required']]]],
    ['{"age":1,"\u0061ge":2}', '', '', '', 65, ['status' => 400, 'error' => 'invalid_json', 'fields' => ['body' => ['duplicate_key']]]],
    ['{}', 'page=1&page=2', 'patch', '', 65, ['status' => 400, 'error' => 'invalid_query', 'fields' => ['query.page' => ['duplicate_scalar']]]],
    ['{}', 'page=%ZZ', 'patch', '', 65, ['status' => 400, 'error' => 'invalid_query', 'fields' => ['query' => ['invalid_encoding']]]],
    ['{}', 'page=99999999999999999999999', 'patch', '', 65, ['status' => 422, 'error' => 'validation_failed', 'fields' => ['page' => ['type_integer']]]],
    ['{"name":"' . str_repeat('a', 2050) . '"}', '', '', '', 65, ['status' => 413, 'error' => 'payload_too_large', 'fields' => ['body' => ['too_large']]]],
    [str_repeat('{"x":', 10) . '1' . str_repeat('}', 10), '', '', '', 65, ['status' => 400, 'error' => 'invalid_json', 'fields' => ['body' => ['too_deep']]]],
];
foreach ($cases as $index => [$body, $query, $patch, $scenario, $code, $expected]) {
    [$status, $stdout, $stderr] = execute([...$command, $body, $query, $patch, $scenario]);
    expect($status === $code && $stderr === '' && json_decode($stdout, true) === $expected, '校验行为不符：' . $index . ' ' . $stdout . $stderr);
}
$schema = new Schema(['value' => Field::text()->nullable(), 'origin' => Field::text()->from('header', 'X-Origin')]);
$data = $schema->validate(new Input(['body' => ['value' => null, 'origin' => '不应选中'], 'header' => ['X-Origin' => '显式来源']]), 'default', true);
expect($data->has('value') && $data->get('value') === null && !$data->has('missing') && $data->get('origin') === '显式来源', '字段存在性或来源混合');
$dto = $data->map(static function (Data $values): stdClass {
    $dto = new stdClass();
    $dto->origin = $values->get('origin');
    return $dto;
});
expect($dto->origin === '显式来源', 'DTO 工厂结果错误');
echo '输入来源、嵌套 DTO、PATCH 与校验错误通过，共 ' . count($cases) . " 个应用入口用例。\n";
