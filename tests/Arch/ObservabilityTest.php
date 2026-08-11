<?php

declare(strict_types=1);

$appFiles = static function (): array {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS),
    );

    return array_values(array_filter(
        iterator_to_array($files),
        fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
    ));
};

it('never logs at debug level', function (): void {
    // A debug line is one nobody enables in production and nobody deletes
    // afterwards. Each unit of work emits exactly one Log::info instead.
    expect('App')->toNeverLogAtDebugLevel();
});

it('namespaces every context key', function (): void {
    // Context keys travel to Nightwatch alongside the framework's own, so an
    // un-namespaced key is a key nobody can filter on.
    expect('App')->toNamespaceContextKeysWith('logralo.');
});

it('actually has observability to police', function () use ($appFiles): void {
    // Both rules above pass trivially if the arch layer ever stops seeing app/,
    // so this pins them to the code they are supposed to be guarding.
    $contents = array_map(
        fn (SplFileInfo $file): string => (string) file_get_contents($file->getPathname()),
        $appFiles(),
    );

    $body = implode("\n", $contents);

    expect(mb_substr_count($body, 'Context::add('))->toBeGreaterThan(20)
        ->and(mb_substr_count($body, 'Log::info('))->toBeGreaterThan(5)
        ->and($body)->toContain("Log::info('mark.create.handled')");
});
