<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/../custom/static-plugins/FibBookingSystem/src',
        __DIR__ . '/../custom/static-plugins/FibBookingSystem/tests',
        __DIR__ . '/../custom/static-plugins/FibBookingDemoData/src',
        __DIR__ . '/../custom/static-plugins/FibBookingDemoData/tests',
        __DIR__ . '/../custom/static-plugins/FibBookingTheme/src',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@Symfony' => true,
        'array_syntax' => ['syntax' => 'short'],
        'blank_line_after_namespace' => true,
        'binary_operator_spaces' => ['default' => 'single_space'],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'method_argument_space' => [
            'on_multiline' => 'ensure_fully_multiline',
        ],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'global_namespace_import' => [
            'import_classes' => true,
        ],
        'single_quote' => true,
        'yoda_style' => [
            'equal' => false,
            'identical' => false,
            'less_and_greater' => false,
        ],
        'strict_comparison' => true,
        'strict_param' => true,
    ])
    ->setFinder($finder)
;
