<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector;
use Rector\PHPUnit\Set\PHPUnitSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

/**
 * Run as a check rather than a rewrite: CI uses --dry-run, so modernisation drift
 * is reported on a pull request instead of being applied silently.
 *
 * Scoped to the module written for this task. The provided Notifications module is
 * left exactly as delivered.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src/Modules/Invoices',
        __DIR__.'/tests/Unit/Invoices',
        __DIR__.'/tests/Feature/Invoices',
        __DIR__.'/tests/E2E',
        __DIR__.'/tests/Support',
        __DIR__.'/tests/Architecture',
    ])
    ->withSets([
        // Matches composer.json's `php: ^8.5`. It was UP_TO_PHP_84, which meant the
        // one version the project actually requires was the one version whose
        // modernisations nothing checked.
        LevelSetList::UP_TO_PHP_85,

        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,

        // Structural rather than cosmetic, so they do not fight Pint: CODING_STYLE
        // and NAMING are deliberately left out — the first overlaps Pint's remit and
        // the second renames deliberate domain vocabulary.
        SetList::EARLY_RETURN,
        SetList::INSTANCEOF,
        SetList::IF,
        SetList::PRIVATIZATION,

        // The suite is a first-class part of this deliverable, so it gets the same
        // treatment as the source: narrower assertions catch more, and PHPUnit 13
        // already pushed the stub/mock distinction in this direction.
        PHPUnitSetList::PHPUNIT_CODE_QUALITY,
        PHPUnitSetList::PHPUNIT_NARROW_ASSERTS,
    ])
    ->withSkip([
        // The suite calls assertions statically throughout, which is a deliberate
        // convention and the one PHPUnit's own documentation uses: a static call
        // cannot be mistaken for test-case state. This rule would rewrite every
        // assertion in the suite to $this-> and unwrap the line-wrapped ones on the
        // way, which is churn in exchange for nothing.
        PreferPHPUnitThisCallRector::class,
    ])
    ->withImportNames(importShortClasses: false)
    // On CI the cache lives in the workspace so actions/cache can carry it between
    // runs; locally it stays in the system temp dir so the project directory stays
    // clean (the same split phpstan-ci.neon makes for PHPStan).
    ->withCache(cacheDirectory: getenv('CI') !== false
        ? __DIR__.'/build/rector'
        : sys_get_temp_dir().'/rector-laravel-ddd-invoices');
