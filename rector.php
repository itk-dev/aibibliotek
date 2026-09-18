<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Rector configuration.
 *
 * Deliberately conservative: the dead-code set plus the PHP 8.4
 * language migration, and nothing else. Rector can rewrite a great
 * deal of code, and a configuration that enables every set at once
 * produces diffs nobody wants to review — so sets are added one at a
 * time, each in its own pull request, once the previous one is
 * understood.
 *
 * `config/` is left out on purpose: it holds Symfony configuration
 * rather than application logic, and `public/index.php` is generated
 * by the framework skeleton.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withPhpSets(php84: true)
    ->withPreparedSets(deadCode: true);
