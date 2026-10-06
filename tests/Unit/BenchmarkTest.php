<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Type\Build\ArtifactManifest;
use Type\Build\BuildIdentity;

require_once dirname(__DIR__) . '/support.php';

/** 只验证性能证据的来源边界；合成数值不作为真实程序性能结论。 */
final class BenchmarkTest extends TestCase
{
    /** @return iterable<string,array{string}> 保留历史读取，并拒绝新协议缺项或跨源码拼接。 */
    public static function evidenceCases(): iterable
    {
        foreach (['valid', 'legacy', 'changed-preparation', 'wrong-source', 'wrong-artifact', 'wrong-runtime', 'wrong-platform', 'missing-generation-round', 'missing-declaration',
            'static-valid', 'static-windows-valid', 'static-wrong-profile', 'static-mixed-sdk', 'static-changed-sdk-archive', 'static-reordered-sdk-archives',
            'static-missing-candidate', 'static-changed-timing',
            'static-other-database', 'static-shared-ini', 'static-missing-sampling'] as $case) {
            yield $case => [$case];
        }
    }

    /** 从公开比较命令验证准备、测量原件与固定源码必须对应同一组身份。 */
    #[DataProvider('evidenceCases')]
    public function testComparisonBindsPreparationAndMeasurements(string $case): void
    {
        $root = dirname(__DIR__, 2);
        $static = str_starts_with($case, 'static-');
        $platform = $case === 'static-windows-valid' || $case === 'static-missing-sampling' ? 'Windows' : PHP_OS_FAMILY;
        $nonce = bin2hex(random_bytes(6));
        $preparationDirectory = 'build/' . ($static ? 'pb-' : 'platform-benchmarks-') . $nonce;
        $measurementDirectory = 'build/benchmark-pairs-' . $nonce;
        $directories = [];
        try {
            foreach ([$preparationDirectory, $measurementDirectory] as $relative) {
                self::assertTrue(mkdir($root . '/' . $relative, 0700));
                $directories[] = $root . '/' . $relative;
            }
            $sources = ['old' => str_repeat('a', 40), 'new' => str_repeat('b', 40)];
            $preparation = ['protocol' => $static ? 3 : 2, 'status' => 'prepared-not-measured',
                'delivery' => $static ? 'static-profile-benchmark' : 'shared-runtime-benchmark', 'static_profile' => $static ? 'sqlite' : null,
                'platform' => $platform, 'architecture' => php_uname('m'), 'source_commits' => $sources, 'variants' => []];
            // 归档和头文件映射按 SDK 导出顺序给出，原生清单会递归排序映射；链接列表顺序仍属于身份。
            $sdk = ['protocol' => 1, 'profile' => 'sqlite', 'features' => ['web'], 'php' => PHP_VERSION,
                'archives' => [['file' => 'lib/libphp.a', 'sha256' => str_repeat('1', 64)], ['file' => 'lib/libphpx.a', 'sha256' => str_repeat('2', 64)]],
                'headers' => [['sha256' => str_repeat('3', 64), 'file' => 'include/php/main/php.h']],
                'patches' => ['SwooleThreadSource' => str_repeat('4', 64), 'PhpxThreadSource' => str_repeat('5', 64)]];
            foreach (['old', 'new'] as $version) {
                $manifest = ['protocol' => $version === 'old' ? 1 : 2, 'inputs' => ['fixture' => hash('sha256', $version)]];
                $generatedFiles = ['fixture.php' => hash('sha256', '<?php // ' . $version)];
                if ($version === 'new') {
                    $manifest['declarations'] = ['files' => $generatedFiles];
                    if ($case !== 'missing-declaration') {
                        $manifest['declaration-generation'] = str_repeat('7', 64);
                    }
                } else {
                    $manifest['files'] = $generatedFiles;
                }
                $preparation['variants'][$version]['roles']['project'] = ['sha256' => hash('sha256', $version . '-program'),
                    'build_id' => hash('sha256', $version . '-build'), 'runtime_ini_sha256' => hash('sha256', $version . '-ini'),
                    'runtime_linkage' => $static ? 'static' : 'shared', 'bytes' => 100, 'build_seconds' => 2,
                    'generation' => ['repetitions' => 3, 'reuse_warmup' => 1, 'cold_seconds' => [0.2, 0.21, 0.22], 'reuse_seconds' => [0.1, 0.11, 0.12],
                        'identity' => ['generation' => hash('sha256', $this->encode($manifest)), 'declaration_generation' => $manifest['declaration-generation'] ?? null,
                            'generated_bytes' => 20, 'manifest' => $manifest]]];
                if ($static) {
                    $preparation['sdk_manifest_sha256'] = str_repeat('e', 64);
                    $preparation['sdk_identity'] = $sdk;
                    $preparation['php_memory_limit'] = '2G';
                    $preparation['controller_ini_sha256'] = str_repeat('6', 64);
                    $preparation['variants'][$version]['sdk_inputs'] = ['toolchain.lock.json' => str_repeat('d', 64)];
                    $embeddedSdk = $sdk;
                    if ($version === 'old' && $case === 'static-changed-sdk-archive') {
                        $embeddedSdk['archives'][0]['sha256'] = str_repeat('f', 64);
                    } elseif ($version === 'old' && $case === 'static-reordered-sdk-archives') {
                        $embeddedSdk['archives'] = array_reverse($embeddedSdk['archives']);
                    }
                    // 只封装可静态读取的协议字节，不执行夹具或宣称 SDK/程序已通过真实原生验收。
                    $binary = substr_replace(str_pad("\x7fELF\x02\x01", 64, "\0"), pack('v', 2), 16, 2);
                    $embedded = $this->encode(BuildIdentity::canonical(['manifest-protocol' => 1, 'binary-format' => 'ELF',
                        'elf-bytes' => strlen($binary), 'elf-sha256' => hash('sha256', $binary), 'build-id' => hash('sha256', $version . '-build'),
                        'runtime-linkage' => 'static', 'static-runtime' => $embeddedSdk]));
                    $fixture = $root . '/' . $preparationDirectory . '/' . $version . '.bin';
                    file_put_contents($fixture, $binary . $embedded . pack('N', strlen($embedded)) . 'TYPEAPP1');
                    $artifactManifest = (new ArtifactManifest())->read($fixture);
                    self::assertNotSame($embeddedSdk, $artifactManifest['static-runtime']);
                    self::assertSame(BuildIdentity::digest($embeddedSdk), BuildIdentity::digest($artifactManifest['static-runtime']));
                    $preparation['variants'][$version]['roles']['project'] = array_replace($preparation['variants'][$version]['roles']['project'], [
                        'runtime_ini_sha256' => $case === 'static-shared-ini' ? str_repeat('f', 64) : null,
                        'profile' => ['name' => 'sqlite', 'database' => 'sqlite', 'features' => ['web']],
                        'sdk_manifest_sha256' => $case === 'static-mixed-sdk' && $version === 'old' ? str_repeat('c', 64) : str_repeat('e', 64),
                        'sdk_identity_sha256' => BuildIdentity::digest($artifactManifest['static-runtime']),
                        'report_sha256' => hash('sha256', $version . '-build-report'),
                        'runtime_extensions' => ['pdo', 'pdo_sqlite', 'swoole'], 'static_archives' => ['libphp.a'], 'system_libraries' => ['libc.so.6'],
                        'timing_scope' => 'vendor/bin/type process; frontend and SDK preparation excluded',
                    ]);
                }
            }
            if ($static) {
                self::assertTrue(mkdir($root . '/' . $preparationDirectory . '/new', 0700));
                $prepared = $preparation['variants']['new']['roles']['project'];
                $candidate = ['protocol' => 2, 'status' => 'passed', 'delivery' => 'single-executable', 'source' => $sources['new'],
                    'profile' => 'sqlite', 'database' => 'sqlite', 'sha256' => $prepared['sha256'], 'build-id' => $prepared['build_id'], 'bytes' => $prepared['bytes'],
                    'sdk-manifest-sha256' => str_repeat('e', 64), 'features' => ['web'],
                    'acceptance' => ['sqlite' => ['status' => 'passed', 'artifact-sha256' => $prepared['sha256']]]];
                $timing = ['protocol' => 1, 'source' => $sources['new'], 'profile' => 'sqlite', 'sha256' => $prepared['sha256'],
                    'build_id' => $prepared['build_id'], 'build_seconds' => 2, 'report_sha256' => $prepared['report_sha256'],
                    'php_memory_limit' => '2G', 'controller_ini_sha256' => str_repeat('6', 64),
                    'sdk_manifest_sha256' => str_repeat('e', 64), 'timing_scope' => $prepared['timing_scope']];
                foreach (['candidate' => ['candidate.json', $candidate], 'candidate_build' => ['build-timing.json', $timing]] as $key => [$name, $data]) {
                    $relative = $preparationDirectory . '/new/' . $name;
                    file_put_contents($root . '/' . $relative, $this->encode($data));
                    $preparation[$key] = ['file' => $relative, 'sha256' => hash_file('sha256', $root . '/' . $relative), 'record' => $data];
                }
                if ($case === 'static-missing-candidate') {
                    unlink($root . '/' . $preparation['candidate']['file']);
                } elseif ($case === 'static-changed-timing') {
                    file_put_contents($root . '/' . $preparation['candidate_build']['file'], "\n", FILE_APPEND);
                } elseif ($case === 'static-wrong-profile') {
                    $preparation['static_profile'] = 'mysql';
                }
            }
            if ($case === 'missing-generation-round') {
                array_pop($preparation['variants']['new']['roles']['project']['generation']['cold_seconds']);
            }
            $preparationFile = $preparationDirectory . '/preparation.json';
            file_put_contents($root . '/' . $preparationFile, $this->encode($preparation));
            $report = ['protocol' => $static ? 3 : 2, 'status' => 'measured-not-compared', 'platform' => $platform, 'architecture' => php_uname('m'),
                'delivery' => $static ? 'static-profile-benchmark' : 'shared-runtime-benchmark', 'static_profile' => $static ? 'sqlite' : null,
                'preparation' => ['file' => $preparationFile, 'sha256' => hash_file('sha256', $root . '/' . $preparationFile)],
                'source_commits' => $sources, 'runs' => []];
            foreach ($static ? ['sqlite'] : ['sqlite', 'mysql', 'pgsql'] as $driver) {
                foreach (['old', 'new'] as $version) {
                    $prepared = $preparation['variants'][$version]['roles']['project'];
                    $measurement = ['status' => 'passed', 'transport' => 'swoole', 'driver' => $driver, 'sampling_protocol' => $static && $platform === 'Windows' ? 3 : 2,
                        'delivery' => $static ? 'static-profile-benchmark' : 'shared-runtime-benchmark', 'static_profile' => $static ? 'sqlite' : null,
                        'host' => ['os' => $case === 'wrong-platform' ? 'incorrect' : $platform, 'architecture' => php_uname('m')],
                        'artifact_sha256' => $case === 'wrong-artifact' ? str_repeat('9', 64) : $prepared['sha256'], 'repetitions' => []];
                    if ($static) {
                        $measurement['sampling'] = ['method' => $platform === 'Windows' ? 'windows-cim-system-diagnostics-v1' : 'unix-ps-process-tree-v2', 'every_operations' => 10];
                        if ($case === 'static-other-database') {
                            $measurement['static_profile'] = 'mysql';
                        } elseif ($case === 'static-missing-sampling') {
                            unset($measurement['sampling']);
                        }
                    }
                    for ($round = 0; $round < 3; $round++) {
                        $row = ['iterations' => 100, 'warmup' => 10, 'concurrency' => 1, 'p50_ms' => 1.0, 'p95_ms' => 2.0, 'p99_ms' => 3.0,
                            'operations_per_second' => 10.0, 'cpu_seconds' => 1.0, 'max_sampled_rss_bytes' => 1000];
                        $measurement['repetitions'][] = ['short-json' => $row, 'crud' => $row, 'slow-database' => $row];
                    }
                    $relative = 'build/application-benchmark-' . bin2hex(random_bytes(6));
                    self::assertTrue(mkdir($root . '/' . $relative, 0700));
                    $directories[] = $root . '/' . $relative;
                    file_put_contents($root . '/' . $relative . '/verification.json', $this->encode($measurement));
                    $report['runs'][] = ['version' => $version, 'transport' => 'swoole', 'driver' => $driver,
                        'source' => $case === 'wrong-source' ? str_repeat('c', 40) : $sources[$version], 'build_id' => $prepared['build_id'],
                        'runtime_ini_sha256' => $case === 'wrong-runtime' ? str_repeat('8', 64) : $prepared['runtime_ini_sha256'],
                        'report' => $relative . '/verification.json', 'sha256' => hash_file('sha256', $root . '/' . $relative . '/verification.json'),
                        'measurement' => $measurement];
                }
            }
            if ($case === 'legacy') {
                unset($report['protocol'], $report['preparation'], $report['source_commits']);
            } elseif ($case === 'changed-preparation') {
                file_put_contents($root . '/' . $preparationFile, "\n", FILE_APPEND);
            }
            $measurementFile = $root . '/' . $measurementDirectory . '/verification.json';
            file_put_contents($measurementFile, $this->encode($report));
            [$status, $stdout, $stderr] = \execute([PHP_BINARY, $root . '/tests/benchmark-compare.php', $measurementFile], $root);
            if (in_array($case, ['valid', 'legacy', 'static-valid', 'static-windows-valid'], true)) {
                self::assertSame(0, $status, $stdout . $stderr);
                $files = glob($root . '/' . $measurementDirectory . '/comparison-*.json');
                self::assertCount(1, $files);
                $comparison = json_decode((string) file_get_contents($files[0]), true, 512, JSON_THROW_ON_ERROR);
                self::assertCount($case === 'legacy' ? 0 : 2, $comparison['generation_and_build']);
                if ($case !== 'legacy') {
                    self::assertSame($sources['new'], $comparison['generation_and_build']['new']['source']);
                    self::assertNull($comparison['generation_and_build']['old']['declaration_generation']);
                    self::assertSame(str_repeat('7', 64), $comparison['generation_and_build']['new']['declaration_generation']);
                }
                if ($static) {
                    self::assertSame('sqlite', $comparison['static_profile']);
                    self::assertCount(1, $comparison['pairs']);
                    self::assertFalse($comparison['size_growth_requires_explanation']);
                }
            } else {
                self::assertNotSame(0, $status);
                $message = match ($case) {
                    'changed-preparation' => '固定源码准备报告缺失或摘要不符',
                    'missing-generation-round' => '生成计时缺少三轮原始样本',
                    'missing-declaration' => '准备报告缺少固定源码和完整生成身份',
                    'static-wrong-profile' => '准备与测量的源码或平台身份不符',
                    'static-mixed-sdk', 'static-shared-ini', 'static-changed-sdk-archive', 'static-reordered-sdk-archives' => '静态准备的 profile、SDK 或部署环境身份不符',
                    'static-missing-candidate', 'static-changed-timing' => '原候选或原编译计时缺失、被替换',
                    'static-other-database' => '静态成对测量混入其他 profile',
                    'static-missing-sampling' => '基准平台、传输、驱动或采样协议不可比',
                    default => '测量程序、运行配置或源码不属于固定准备身份',
                };
                self::assertStringContainsString($message, $stdout . $stderr);
                self::assertSame([], glob($root . '/' . $measurementDirectory . '/comparison-*.json'));
            }
        } finally {
            foreach (array_reverse($directories) as $directory) {
                \removeTestDirectory($directory);
            }
        }
    }

    /** 清单身份沿用生成器的 JSON 字节规则，保留 Unicode 和路径分隔符。 */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
