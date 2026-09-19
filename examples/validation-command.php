<?php

declare(strict_types=1);

use Type\Validate\Input;
use Type\Validate\ValidationException;
use TypeApp\ValidationExample\UserInput;

function main(int $argc, array $argv): void
{
    try {
        $input = Input::json((string) ($argv[1] ?? '{}'), 2048, 8)->withQuery((string) ($argv[2] ?? ''));
        $data = UserInput::schema()->validate($input, (string) ($argv[4] ?? 'default'), ($argv[3] ?? '') === 'patch');
        echo json_encode(['data' => $data->toArray()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } catch (ValidationException $error) {
        echo json_encode(['status' => $error->status(), 'error' => $error->errorCode(), 'fields' => $error->errors()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(65);
    }
}
