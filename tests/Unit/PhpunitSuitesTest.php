<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

function phpunitConfig(): SimpleXMLElement
{
    return new SimpleXMLElement(File::get(base_path('phpunit.xml')));
}

/**
 * @return Collection<int, string>
 */
function phpunitPaths(SimpleXMLElement $suite, string $element): Collection
{
    return collect($suite->xpath($element) ?: [])
        ->map(fn (SimpleXMLElement $path): string => base_path(mb_trim((string) $path)));
}

// This file lives in tests/Unit because the Laravel skeleton always declares
// that suite. A guard inside tests/Arch cannot report that tests/Arch is the
// directory nobody declared.
it('collects every test file into a testsuite', function (): void {
    $covers = fn (string $root, string $path): bool => $root === $path
        || str_starts_with($path, $root.DIRECTORY_SEPARATOR);

    // Each suite is read the way PHPUnit reads it: <exclude> narrows the
    // <directory> scan of its own suite, and never touches a named <file>.
    $suites = collect(phpunitConfig()->xpath('//testsuites/testsuite') ?: [])
        ->map(function (SimpleXMLElement $suite) use ($covers): Closure {
            $directories = phpunitPaths($suite, 'directory');
            $excluded = phpunitPaths($suite, 'exclude');
            $files = phpunitPaths($suite, 'file');

            return fn (string $path): bool => $files->contains($path)
                || ($directories->contains(fn (string $root): bool => $covers($root, $path))
                    && $excluded->doesntContain(fn (string $root): bool => $covers($root, $path)));
        });

    $uncollected = collect(File::allFiles(base_path('tests')))
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->filter(fn (string $path): bool => str_ends_with($path, 'Test.php'))
        ->reject(fn (string $path): bool => $suites->contains(fn (Closure $collects): bool => $collects($path)))
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

    /** @var array<string, string|array<int, string>> $scripts */
    $scripts = File::json(base_path('composer.json'))['scripts'] ?? [];

    // --testsuite takes a comma-separated list, so the names are matched whole
    // rather than searched for: suite Arch is not selected by --testsuite=Architecture.
    $selected = collect($scripts)
        ->flatten()
        ->flatMap(fn (string $script): array => Str::matchAll('/--testsuite[= ]([^\s"\']+)/', $script)->all())
        ->flatMap(fn (string $names): array => explode(',', $names))
        ->map(fn (string $name): string => mb_trim($name));

    // With no defaultTestSuite, PHPUnit runs every declared suite.
    $unreachable = collect($config->xpath('//testsuites/testsuite') ?: [])
        ->map(fn (SimpleXMLElement $suite): string => (string) $suite['name'])
        ->reject(fn (string $name): bool => $default->isEmpty()
            || $default->contains($name)
            || $selected->contains($name))
        ->sort()
        ->implode(', ');

    expect($unreachable)->toBe('');
});
