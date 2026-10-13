<?php

/**
 * No two test files may declare the same top-level function.
 *
 * Pest loads every test file into the SAME namespace. Two functions of the same
 * name in different files are therefore not a style issue but a fatal error —
 * and it happens while LOADING:
 *
 *     PHP Fatal error: Cannot redeclare function block()
 *
 * The suite stops before a single test runs. No test counts, just an exit code,
 * which reads as "everything is red". Each file on its own stays green, which
 * sends the search in the wrong direction.
 *
 * Happened twice on 13.10.2026 in two different repositories, both times
 * between people who could not see each other's helper: `brainAntwortet()` in a
 * consuming application, `block()` here.
 */
it('declares no helper function twice', function () {
    $wurzel = dirname(__DIR__);

    $verzeichnis = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS)
    );

    $funde = [];

    foreach ($verzeichnis as $datei) {
        if (! $datei->isFile() || $datei->getExtension() !== 'php') {
            continue;
        }

        // Only top-level functions — an indented one lives in a class or a
        // closure and does not share the namespace.
        preg_match_all('/^function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m', file_get_contents($datei->getPathname()), $treffer);

        foreach ($treffer[1] as $name) {
            $funde[$name][] = str_replace($wurzel.'/', '', $datei->getPathname());
        }
    }

    $doppelt = array_filter($funde, fn (array $dateien) => count(array_unique($dateien)) > 1);

    // Counter-check: without it this test would also be green if the pattern
    // never matched anything — and then it measures nothing.
    expect($funde)->not->toBeEmpty();

    expect($doppelt)->toBe([]);
});
