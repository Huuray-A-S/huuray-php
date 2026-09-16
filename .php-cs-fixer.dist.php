<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
        __DIR__ . '/scripts',
    ])
    ->append([
        __DIR__ . '/bin/huuray',
        __FILE__,
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS' => true,
        // Every PHP file declares strict_types; this keeps it that way.
        'declare_strict_types' => true,
    ])
    ->setFinder($finder);
