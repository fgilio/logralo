<?php

declare(strict_types=1);

use Pest\Arch\Contracts\ArchExpectation;
use Pest\Arch\Expectations\Targeted;
use Pest\Arch\Support\FileLineFinder;
use PHPUnit\Architecture\Elements\ObjectDescription;

/**
 * Custom architecture expectations.
 *
 * Pest boots this file for us, so every `expect()` below is available to the
 * whole suite. The `ArchExpectation` return type is load-bearing: Pest only
 * treats an extension as an architecture rule when the closure declares
 * exactly that type, otherwise it wraps the result in a value expectation.
 */

/**
 * Asserts the class itself declares the method, not merely inherits it.
 *
 * `toHaveMethod()` is satisfied by anything reflection can reach, and every
 * Eloquent model inherits `casts()` from the base Model. This is the version
 * that actually catches a model that forgot to declare its own.
 */
expect()->extend('toDeclareMethod', fn (string $method): ArchExpectation => Targeted::make(
    $this, // @phpstan-ignore-line
    function (ObjectDescription $object) use ($method): bool {
        if (! isset($object->reflectionClass) || ! $object->reflectionClass->hasMethod($method)) {
            return false;
        }

        return $object->reflectionClass->getMethod($method)->getDeclaringClass()->getName() === $object->name;
    },
    sprintf('to declare its own [%s()] method', $method),
    FileLineFinder::where(fn (string $line): bool => str_contains($line, 'class')),
));

/**
 * Asserts nothing logs at debug level.
 *
 * Debug lines are the ones nobody turns on in production and nobody deletes
 * afterwards. Logralo logs one canonical `Log::info` per unit of work instead.
 */
expect()->extend('toNeverLogAtDebugLevel', fn (): ArchExpectation => Targeted::make(
    $this, // @phpstan-ignore-line
    fn (ObjectDescription $object): bool => preg_match(
        '/\bLog::debug\s*\(|\bLog::(?:channel|stack|build)\s*\([^)]*\)\s*->\s*debug\s*\(|\blogger\s*\(\s*\)\s*->\s*debug\s*\(/',
        (string) file_get_contents($object->path),
    ) === 0,
    'to never log at debug level',
    FileLineFinder::where(fn (string $line): bool => str_contains($line, 'debug')),
));

/**
 * Asserts every `Context` key carries the given prefix.
 *
 * Context keys ride along into Nightwatch next to the framework's own, so an
 * un-namespaced key is a key nobody can filter on. A call this rule cannot
 * read as a literal string key also fails: an unparseable key is an
 * unenforceable one.
 */
expect()->extend('toNamespaceContextKeysWith', fn (string $prefix): ArchExpectation => Targeted::make(
    $this, // @phpstan-ignore-line
    function (ObjectDescription $object) use ($prefix): bool {
        $contents = (string) file_get_contents($object->path);

        $calls = preg_match_all('/\bContext::(?:add|addHidden|push|pushHidden|increment|decrement)\s*\(/', $contents);
        $keys = preg_match_all(
            '/\bContext::(?:add|addHidden|push|pushHidden|increment|decrement)\s*\(\s*[\'"]([^\'"]+)[\'"]/',
            $contents,
            $matches,
        );

        if ($calls !== $keys) {
            return false;
        }

        return array_all($matches[1], fn (string $key): bool => str_starts_with($key, $prefix));
    },
    sprintf('to namespace every Context key under [%s]', $prefix),
    FileLineFinder::where(fn (string $line): bool => str_contains($line, 'Context::')),
));
