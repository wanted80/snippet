<?php

declare(strict_types=1);

namespace Snippet\Preview;

use Snippet\Tests\PublisherFaults;

use function filesize;

/**
 * @param int<0, 2> $sortingOrder
 * @return list<string>|false
 */
function scandir(string $directory, int $sortingOrder = SCANDIR_SORT_ASCENDING): array|false
{
    return PublisherFaults::fails('preview_scandir') ? false : \scandir($directory, $sortingOrder);
}

function readlink(string $path): string|false
{
    return PublisherFaults::fails('preview_readlink') ? false : \readlink($path);
}

function hash_file(string $algorithm, string $filename): string|false
{
    $size = filesize($filename);
    if (is_int($size) && $size > 65_536 && PublisherFaults::fails('preview_large_hash_file')) {
        return false;
    }

    return PublisherFaults::fails('preview_hash_file') ? false : \hash_file($algorithm, $filename);
}

/** @return array<string|int, int>|false */
function stat(string $filename): array|false
{
    return PublisherFaults::fails('preview_stat') ? false : \stat($filename);
}
