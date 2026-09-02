<?php

declare(strict_types=1);

namespace Snippet\Support;

use Uri\Rfc3986\Uri;

use function preg_replace_callback;
use function rawurlencode;

/** Parses authored URI references while preserving support for raw UTF-8 path text. */
final readonly class UriReferenceParser
{
    public static function parse(string $reference): ?Uri
    {
        $uri = preg_replace_callback(
            '/[^\x00-\x7F]+/u',
            static fn(array $match): string => rawurlencode($match[0]),
            $reference,
        );

        return $uri === null ? null : Uri::parse($uri);
    }
}
