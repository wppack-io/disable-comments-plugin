<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->append([__DIR__ . '/wppack-disable-comments.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PER-CS2.0:risky' => true,
        'declare_strict_types' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
        'header_comment' => [
            'header' => <<<'EOT'
This file is part of the WPPack package.

(c) Tsuyoshi Tsurushima

For the full copyright and license information, please view the LICENSE
file that was distributed with this source code.
EOT,
            'comment_type' => 'comment',
            'location' => 'after_open',
            'separate' => 'both',
        ],
    ])
    ->setFinder($finder);
