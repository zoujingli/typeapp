<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

// 统一 PSR-12 与排版规则，保留 TypePHP 语义和第三方许可证边界。
$finder = Finder::create()
    ->in([__DIR__ . '/app', __DIR__ . '/plugin', __DIR__ . '/config', __DIR__ . '/tests', __DIR__ . '/tools', __DIR__ . '/examples', __DIR__ . '/templates'])
    ->name('*.php')
    ->exclude(['vendor', 'build', 'runtime', 'fixtures'])
    ->append([
        __DIR__ . '/bin/typeapp',
        __DIR__ . '/bin/typeapp-prepare',
        __DIR__ . '/plugin/type-build/bin/type',
        __DIR__ . '/plugin/type-build/bin/type-compiler',
        __FILE__,
    ]);

return (new Config())
    ->setFinder($finder)
    ->setRiskyAllowed(false)
    ->setUsingCache(false)
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        '@PhpCsFixer' => true,
        'array_syntax' => ['syntax' => 'short'],
        'list_syntax' => ['syntax' => 'short'],
        'blank_line_before_statement' => ['statements' => ['declare']],
        'general_phpdoc_annotation_remove' => ['annotations' => ['author']],
        // 属性读写提示承载模型约束，不能被当作别名合并。
        'phpdoc_no_alias_tag' => ['replacements' => ['type' => 'var', 'link' => 'see']],
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        // 自动插入 use 在带 shebang 的命令中会越过 strict_types；只整理显式导入。
        'fully_qualified_strict_types' => ['import_symbols' => false],
        'single_line_comment_style' => ['comment_types' => []],
        'yoda_style' => ['always_move_variable' => false, 'equal' => false, 'identical' => false],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'no_multi_line'],
        'constant_case' => ['case' => 'lower'],
        'encoding' => true,
        'concat_space' => ['spacing' => 'one'],
        'cast_spaces' => ['space' => 'none'],
        'single_quote' => true,
        'no_empty_statement' => true,
        'standardize_not_equals' => true,
        'blank_line_after_namespace' => true,
        'no_empty_comment' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_leading_import_slash' => true,
        'class_attributes_separation' => true,
        'combine_consecutive_unsets' => true,
        'lowercase_static_reference' => true,
        'linebreak_after_opening_tag' => true,
        'multiline_comment_opening_closing' => true,
        'no_useless_else' => true,
        'no_unused_imports' => true,
        'not_operator_with_successor_space' => false,
        'not_operator_with_space' => false,
        'ordered_class_elements' => true,
        'php_unit_strict' => false,
        'phpdoc_separation' => false,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_trim' => true,
        'no_empty_phpdoc' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'single_blank_line_at_eof' => true,
        'line_ending' => true,
        // 格式工具不改写闭包和严格类型的执行语义。
        'use_arrow_functions' => false,
        'declare_strict_types' => false,
    ]);
