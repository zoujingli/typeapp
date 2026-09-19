<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
$work = $argv[1];
$input = json_decode(file_get_contents($work . '/identity.json'), true, 512, JSON_THROW_ON_ERROR);
$result = (new Type\Build\ArtifactCache($work . '/shared-cache'))->materialize(
    $input['identity'],
    $input['manifest'],
    $work . '/worker-' . getmypid(),
    static function (string $file) use ($work): void {
        file_put_contents($work . '/compiled.txt', "compiled\n", FILE_APPEND);
        usleep(100000);
        copy('/usr/bin/true', $file);
        chmod($file, 0755);
    }
);
echo json_encode(['hit' => $result['hit'], 'sha256' => $result['artifact-sha256']], JSON_THROW_ON_ERROR);
