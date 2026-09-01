<?php

declare(strict_types=1);

namespace Snippet\Tests;

final class ApplicationClock
{
    /** @var list<float> */
    private static array $readings = [];

    /** @var list<bool> */
    private static array $requests = [];

    /** @param list<float> $readings */
    public static function replace(array $readings): void
    {
        self::$readings = $readings;
        self::$requests = [];
    }

    public static function reset(): void
    {
        self::$readings = [];
        self::$requests = [];
    }

    /** @return array{int, int}|float|null */
    public static function read(bool $asNumber): array|float|null
    {
        if (self::$readings === []) {
            return null;
        }

        self::$requests[] = $asNumber;
        $reading = array_shift(self::$readings);

        return $asNumber ? $reading : [0, (int) $reading];
    }

    /** @return list<bool> */
    public static function requests(): array
    {
        return self::$requests;
    }
}

namespace Snippet;

use Snippet\Tests\ApplicationClock;

/** @return array<int, int>|float|int */
function hrtime(bool $asNumber = false): array|float|int
{
    $reading = ApplicationClock::read($asNumber);

    return $reading ?? \hrtime($asNumber);
}
