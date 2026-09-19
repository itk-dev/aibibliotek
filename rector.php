<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

/*
 * Rector configuration.
 *
 * `withComposerBased()` reads composer.lock and enables only the rules
 * that match the installed versions of Symfony, Doctrine, PHPUnit and
 * Twig, so the migrations stay in step with the dependencies instead of
 * being pinned to a version the project has to remember to bump.
 *
 * The hand-picked sets are the ones whose output is mechanical enough
 * to review in bulk: dead code, code quality, and type declarations.
 * Sets that rewrite structure rather than expression — naming,
 * privatization, early return — are left off; they produce diffs that
 * need judging line by line, which is not what an automated pass is
 * good at.
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
    ->withComposerBased(
        twig: true,
        doctrine: true,
        phpunit: true,
        symfony: true,
    )
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    );
