<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()->in([__DIR__ . '/src', __DIR__ . '/controllers', __DIR__ . '/upgrade'])
    ->append([__DIR__ . '/pixel_audit.php'])->notPath('vendor');

return (new PhpCsFixer\Config())->setRules([
    '@PSR12' => true,
    'array_syntax' => ['syntax' => 'short'],
])->setFinder($finder)->setCacheFile(__DIR__ . '/tools/.php-cs-fixer.cache');
