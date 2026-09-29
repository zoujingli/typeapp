<?php

declare(strict_types=1);

namespace TypeTests\Unit;

use PHPUnit\Framework\TestCase;
use TypeApp\Release\Candidate;
use TypeApp\Release\GitHub;

require_once dirname(__DIR__) . '/support.php';
require_once dirname(__DIR__, 2) . '/tools/distribution/Process.php';
require_once dirname(__DIR__, 2) . '/tools/release/Plan.php';
require_once dirname(__DIR__, 2) . '/tools/release/Candidate.php';
require_once dirname(__DIR__, 2) . '/tools/release/GitHub.php';

/** 在真实命令边界核对分页 Artifact 身份及历史体积基线，哨兵不发送远端请求。 */
final class ReleaseArtifactTest extends TestCase
{
    public function testArtifactsBindEveryProfileToSourceAttemptAndUnexpiredDigest(): void
    {
        $root = dirname(__DIR__, 2) . '/build/release-artifact-' . bin2hex(random_bytes(6));
        mkdir($root . '/commands', 0700, true);
        $path = getenv('PATH');
        try {
            \writeTestPhpCommand($root . '/commands/gh', <<<'PHP'
if ($argv[1] !== 'api' || $argv[2] !== 'repos/zoujingli/typeapp/actions/runs/123/artifacts?per_page=100'
    || !in_array('--paginate', $argv, true) || !in_array('--slurp', $argv, true)) { exit(2); }
echo file_get_contents(getcwd() . '/pages.json');
PHP);
            putenv('PATH=' . $root . '/commands' . PATH_SEPARATOR . $path);
            $source = str_repeat('a', 40);
            $items = [];
            foreach (Candidate::matrix() as $index => $key) {
                $items[] = ['id' => $index + 1, 'name' => 'release-candidate-static-' . $key . '-2',
                    'expired' => false, 'workflow_run' => ['head_sha' => $source], 'digest' => 'sha256:' . str_repeat('b', 64),
                    'size_in_bytes' => 100, 'expires_at' => '2027-01-01T00:00:00Z'];
            }
            $write = static function (array $items) use ($root): void {
                file_put_contents($root . '/pages.json', json_encode([['artifacts' => array_slice($items, 0, 6)], ['artifacts' => array_slice($items, 6)]], JSON_THROW_ON_ERROR));
            };
            $write($items);
            $api = new GitHub($root);
            $receipt = $api->candidateArtifacts('123', '2', $source);
            self::assertSame(Candidate::matrix(), array_keys($receipt));
            self::assertSame(str_repeat('b', 64), $receipt['linux-x64-sqlite']['sha256']);
            foreach (['expired', 'source', 'digest', 'attempt', 'missing', 'duplicate'] as $fault) {
                $invalid = $items;
                switch ($fault) {
                    case 'expired': $invalid[0]['expired'] = true;
                        break;
                    case 'source': $invalid[0]['workflow_run']['head_sha'] = str_repeat('c', 40);
                        break;
                    case 'digest': $invalid[0]['digest'] = '';
                        break;
                    case 'attempt': $invalid[0]['name'] .= '0';
                        break;
                    case 'missing': array_pop($invalid);
                        break;
                    case 'duplicate': $invalid[] = $invalid[0];
                        break;
                }
                $write($invalid);
                try {
                    $api->candidateArtifacts('123', '2', $source);
                    self::fail('Invalid Artifact accepted: ' . $fault);
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Artifact', $error->getMessage());
                }
            }
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
            \removeTestDirectory($root);
        }
    }

    public function testBaselineSkipsDraftsFutureAndHistoricalProtocolsAndPreservesExactBytes(): void
    {
        $root = dirname(__DIR__, 2) . '/build/release-baseline-test-' . bin2hex(random_bytes(6));
        mkdir($root . '/commands', 0700, true);
        mkdir($root . '/.github', 0700);
        copy(dirname(__DIR__, 2) . '/.github/distribution.json', $root . '/.github/distribution.json');
        $path = getenv('PATH');
        try {
            \writeTestPhpCommand($root . '/commands/gh', <<<'PHP'
if ($argv[1] === 'api') { echo file_get_contents(getcwd() . '/releases.json'); exit; }
if ($argv[1] === 'release' && $argv[2] === 'download') {
    $dir = $argv[array_search('--dir', $argv) + 1];
    copy(getcwd() . '/' . $argv[3] . '.json', $dir . '/release-manifest.json'); exit;
}
exit(2);
PHP);
            putenv('PATH=' . $root . '/commands' . PATH_SEPARATOR . $path);
            $releases = [];
            foreach (['v1.0.0-rc.14', 'v1.0.0-rc.13', 'v1.0.0-rc.10', 'v1.0.0-rc.11'] as $i => $version) {
                $releases[] = ['id' => $i + 1, 'tag_name' => $version, 'draft' => $i === 1, 'assets' => [['name' => 'release-manifest.json']]];
            }
            file_put_contents($root . '/releases.json', json_encode($releases, JSON_THROW_ON_ERROR));
            file_put_contents($root . '/v1.0.0-rc.10.json', '{"protocol":2}');
            $baseline = ['protocol' => 3, 'version' => 'v1.0.0-rc.11', 'programs' => array_fill_keys(Candidate::matrix(), ['bytes' => 123])];
            $bytes = json_encode($baseline, JSON_THROW_ON_ERROR) . "\n";
            file_put_contents($root . '/v1.0.0-rc.11.json', $bytes);
            $result = (new GitHub($root))->sizeBaseline('v1.0.0-rc.12');
            self::assertSame($baseline, $result['manifest']);
            self::assertSame(hash('sha256', $bytes), $result['sha256']);
            self::assertSame(4, $result['release-id']);
            self::assertSame([], glob($root . '/build/release-baseline-*'));
        } finally {
            putenv($path === false ? 'PATH' : 'PATH=' . $path);
            \removeTestDirectory($root);
        }
    }
}
