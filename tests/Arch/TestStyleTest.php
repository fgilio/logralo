<?php

declare(strict_types=1);

$testFiles = static function (): array {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/..', FilesystemIterator::SKIP_DOTS),
    );

    return array_values(array_filter(
        iterator_to_array($files),
        fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
    ));
};

/**
 * Whether the file declares at least one test through the named function.
 *
 * A declaration is the function name, an opening parenthesis, and a quoted
 * description. Reading tokens rather than raw text is what separates a real
 * declaration from `Livewire::test(...)`, from `->test(...)`, from the bare
 * `test()` proxy that grabs the TestCase, and from any of the three merely
 * mentioned in a comment or a string.
 */
$declares = static function (string $function, string $contents): bool {
    $tokens = array_values(array_filter(
        token_get_all($contents),
        fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    foreach ($tokens as $index => $token) {
        if (! is_array($token)) {
            continue;
        }

        if ($token[0] !== T_STRING) {
            continue;
        }

        if ($token[1] !== $function) {
            continue;
        }

        if (($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }

        $description = $tokens[$index + 2] ?? null;
        if (! is_array($description)) {
            continue;
        }

        if ($description[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $previous = $tokens[$index - 1] ?? null;

        if (is_array($previous) && in_array($previous[0], [
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_FUNCTION,
            T_NEW,
        ], true)) {
            continue;
        }

        return true;
    }

    return false;
};

it('declares every test with it()', function () use ($testFiles, $declares): void {
    $missing = [];

    foreach ($testFiles() as $file) {
        if (! str_ends_with($file->getFilename(), 'Test.php')) {
            continue;
        }

        if (! $declares('it', (string) file_get_contents($file->getPathname()))) {
            $missing[] = $file->getFilename();
        }
    }

    expect($missing)->toBe([]);
});

it('never declares a test with the test() function', function () use ($testFiles, $declares): void {
    // One spelling, so the whole suite reads as sentences end to end.
    $offenders = [];

    foreach ($testFiles() as $file) {
        if ($declares('test', (string) file_get_contents($file->getPathname()))) {
            $offenders[] = $file->getFilename();
        }
    }

    expect($offenders)->toBe([]);
});

it('finds test files to police', function () use ($testFiles, $declares): void {
    // Guards the two rules above against a silent pass if the scan ever stops
    // finding files, and against a matcher that stops recognising a
    // declaration at all.
    expect(count($testFiles()))->toBeGreaterThan(20)
        ->and($declares('it', (string) file_get_contents(__FILE__)))->toBeTrue();
});
