<?php

declare(strict_types=1);

/**
 * Enforces a line-coverage floor for a path prefix, which PHPUnit itself does not do.
 *
 * Usage: php tools/check-coverage.php <clover.xml> <path-prefix> <minimum-percent>
 */
if ($argc !== 4) {
    fwrite(STDERR, "Usage: php tools/check-coverage.php <clover.xml> <path-prefix> <minimum-percent>\n");
    exit(2);
}

[, $cloverPath, $prefix, $minimum] = $argv;

if (! is_file($cloverPath)) {
    fwrite(STDERR, "Coverage report not found: {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);

if ($xml === false) {
    fwrite(STDERR, "Could not parse {$cloverPath}\n");
    exit(2);
}

$statements = 0;
$covered = 0;

foreach ($xml->xpath('//file') ?: [] as $file) {
    $name = (string) $file['name'];

    if (! str_contains($name, $prefix)) {
        continue;
    }

    $metrics = $file->metrics;

    if ($metrics === null) {
        continue;
    }

    $statements += (int) $metrics['statements'];
    $covered += (int) $metrics['coveredstatements'];
}

if ($statements === 0) {
    fwrite(STDERR, "No covered files matched the prefix '{$prefix}'. Is the coverage filter right?\n");
    exit(2);
}

$percent = round($covered / $statements * 100, 2);
$floor = (float) $minimum;

printf("Line coverage for %s: %.2f%% (%d/%d statements), floor %.2f%%\n", $prefix, $percent, $covered, $statements, $floor);

if ($percent < $floor) {
    fwrite(STDERR, sprintf("Coverage %.2f%% is below the required %.2f%%.\n", $percent, $floor));
    exit(1);
}

exit(0);
