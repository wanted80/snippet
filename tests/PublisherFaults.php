<?php

declare(strict_types=1);

namespace Snippet\Tests;

use RuntimeException;

/** Deterministic fault queue used only by publication failure tests. */
final class PublisherFaults
{
    /** @var array<string, list<'fail'|'partial'|'pass'|'throw'>> */
    private static array $faults = [];

    /** @param list<'fail'|'partial'|'pass'|'throw'> $outcomes */
    public static function set(string $operation, array $outcomes): void
    {
        self::$faults[$operation] = $outcomes;
    }

    public static function reset(): void
    {
        self::$faults = [];
    }

    public static function fails(string $operation): bool
    {
        return self::outcome($operation) === 'fail';
    }

    /** @return 'fail'|'partial'|'pass'|null */
    public static function outcome(string $operation): ?string
    {
        $outcomes = self::$faults[$operation] ?? [];
        $outcome = array_shift($outcomes);
        self::$faults[$operation] = $outcomes;
        if ($outcome === 'throw') {
            throw new RuntimeException("Injected {$operation} failure.");
        }

        return $outcome;
    }
}
