<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * `toContain()` takes any number of needles and asserts every one is present.
 * `not` negates that whole call rather than each needle, so
 * `not->toContain($a, $b)` holds as soon as one of them is missing. Written to
 * mean "contains none of these", it fails only when all of them leak at once,
 * and a second argument passed as a failure message turns the assertion off
 * outright: the subject never contains the message.
 *
 * Say it as one call per needle. When the intent really is "never all
 * together", write the conjunction and assert it is false, where the reader
 * can see it.
 */
it('never negates a toContain with more than one needle', function (): void {
    // The paths come from __DIR__ rather than base_path(): an architecture
    // suite is not always bound to a TestCase, and this rule reads files.
    $root = dirname(__DIR__, 2);

    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;

    $isNegation = function (Node $node): bool {
        if ($node instanceof PropertyFetch) {
            return $node->name instanceof Identifier
                && $node->name->toString() === 'not';
        }

        // `->not()` returns the same OppositeExpectation as `->not`.
        return $node instanceof MethodCall
            && $node->args === []
            && $node->name instanceof Identifier
            && $node->name->toString() === 'not';
    };

    $isNegatedToContain = function (Node $node) use ($isNegation): bool {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return false;
        }

        if ($node->name->toString() !== 'toContain') {
            return false;
        }

        return $isNegation($node->var);
    };

    // A spread carries the same hazard while presenting one argument.
    $multiNeedle = fn (MethodCall $call): bool => count($call->args) > 1
        || collect($call->args)->contains(fn (Node $arg): bool => $arg instanceof Arg && $arg->unpack);

    $offenders = collect(Finder::create()->files()->in($root.'/tests')->name('*.php'))
        ->flatMap(function (SplFileInfo $file) use ($root, $parser, $finder, $isNegatedToContain, $multiNeedle): array {
            $calls = $finder->find($parser->parse($file->getContents()) ?? [], $isNegatedToContain);

            return collect($calls)
                ->filter($multiNeedle)
                ->map(fn (MethodCall $call): string => sprintf(
                    '%s:%d',
                    Str::after($file->getPathname(), $root.DIRECTORY_SEPARATOR),
                    $call->getStartLine(),
                ))
                ->all();
        })
        ->sort()
        ->implode(', ');

    expect($offenders)->toBe('');
});
