<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
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
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_84,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::TYPE_DECLARATION,
    ])
    ->withImportNames(importShortClasses: false);
