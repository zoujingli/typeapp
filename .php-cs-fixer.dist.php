<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// 仅排版及明确的非 risky 规则；不改 TypePHP 参数、类型或运行协议。
$finder = Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/plugin', __DIR__ . '/config', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/examples', __DIR__ . '/templates'])
    ->name('*.php')
    ->exclude(['vendor', 'build', 'runtime', 'fixtures'])
    ->append([__DIR__ . '/bin/typeapp', __DIR__ . '/bin/typeapp-prepare', __FILE__]);

return (new Config())
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'single_quote' => true,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_trim' => true,
        'no_empty_phpdoc' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'line_ending' => true,
    ]);
