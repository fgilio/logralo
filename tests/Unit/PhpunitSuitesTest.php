<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

function phpunitConfig(): SimpleXMLElement
{
    return new SimpleXMLElement(File::get(base_path('phpunit.xml')));
}

// This file lives in tests/Unit because the Laravel skeleton always declares
// that suite. A guard inside tests/Arch cannot report that tests/Arch is the
// directory nobody declared.
it('collects every test file into a testsuite', function (): void {
    $declared = collect(phpunitConfig()->xpath('//testsuites/testsuite/*') ?: [])
        ->map(fn (SimpleXMLElement $path): string => base_path(mb_trim((string) $path)));

    $uncollected = collect(File::allFiles(base_path('tests')))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->filter(fn (string $path): bool => str_ends_with($path, 'Test.php'))
        ->reject(fn (string $path): bool => $declared->contains(
            fn (string $root): bool => $root === $path || str_starts_with($path, $root.DIRECTORY_SEPARATOR),
        ))
        ->map(fn (string $path): string => Str::after($path, base_path().DIRECTORY_SEPARATOR))
        ->sort()
        ->implode(', ');

    expect($uncollected)->toBe('');
});

it('runs every declared testsuite', function (): void {
    $config = phpunitConfig();

    $default = Str::of((string) ($config['defaultTestSuite'] ?? ''))
        ->explode(',')
        ->map(fn (string $name): string => mb_trim($name))
        ->filter();

    $composer = File::get(base_path('composer.json'));

    $unreachable = collect($config->xpath('//testsuites/testsuite') ?: [])
        ->map(fn (SimpleXMLElement $suite): string => (string) $suite['name'])
        ->reject(fn (string $name): bool => $default->isEmpty()
            || $default->contains($name)
            || Str::contains($composer, "--testsuite={$name}"))
        ->sort()
        ->implode(', ');

    expect($unreachable)->toBe('');
});
