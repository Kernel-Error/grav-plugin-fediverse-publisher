<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/classes', __DIR__ . '/tests'])
    ->name('*.php')
    ->name('fediverse-publisher.php')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12'                       => true,
        '@PSR12:risky'                 => true,
        'declare_strict_types'         => true,
        'no_unused_imports'            => true,
        'ordered_imports'              => ['imports_order' => ['class', 'function', 'const']],
        'single_quote'                 => true,
        'trailing_comma_in_multiline'  => true,
        'native_function_invocation'   => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
    ])
    ->setFinder($finder);
