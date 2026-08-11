<?php

declare(strict_types=1);

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * app/Jobs is empty today. Rather than leave the rule out until someone
 * remembers to add it, both tests below arm themselves the moment the first
 * job class lands — they skip while the directory is empty and enforce after.
 */
$jobFiles = static function (): array {
    $directory = dirname(__DIR__, 2).'/app/Jobs';

    if (! is_dir($directory)) {
        return [];
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
    );

    return array_values(array_filter(
        iterator_to_array($files),
        fn (SplFileInfo $file): bool => $file->getExtension() === 'php',
    ));
};

it('queues every job', function (): void {
    expect('App\Jobs')->toImplement(ShouldQueue::class);
})->skip(fn (): bool => $jobFiles() === [], 'No jobs yet — this rule arms itself when app/Jobs gets its first class.');

it('gives every job a handle method', function (): void {
    expect('App\Jobs')->toHaveMethod('handle');
})->skip(fn (): bool => $jobFiles() === [], 'No jobs yet — this rule arms itself when app/Jobs gets its first class.');
