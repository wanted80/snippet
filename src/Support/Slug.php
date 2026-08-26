<?php

declare(strict_types=1);

namespace Snippet\Support;

/** Generates Unicode tag slugs, validates ASCII content slugs, and encodes URI segments. */
final readonly class Slug
{
    /** @var list<string> */
    private const array RESERVED_CONTENT = ['articles', 'assets', 'pages', 'tags'];

    public static function from(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $lowercase = mb_strtolower($value, 'UTF-8');
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $lowercase);

        return mb_trim((string) $slug, '-');
    }

    public static function isCanonicalAscii(string $value): bool
    {
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $value) === 1;
    }

    public static function isReservedContent(string $value): bool
    {
        return in_array($value, self::RESERVED_CONTENT, true);
    }

    /** Percent-encode one UTF-8 slug using the RFC 3986 path-segment form. */
    public static function toUriSegment(string $slug): string
    {
        return rawurlencode($slug);
    }
}
