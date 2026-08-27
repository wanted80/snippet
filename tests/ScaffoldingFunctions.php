<?php

declare(strict_types=1);

namespace Snippet\Scaffolding;

use Snippet\Tests\PublisherFaults;

/** @return resource|false */
function fopen(string $filename, string $mode): mixed
{
    return PublisherFaults::fails('scaffolding_fopen') ? false : \fopen($filename, $mode);
}

/**
 * @param resource $from
 * @param resource $to
 */
function stream_copy_to_stream(mixed $from, mixed $to): int|false
{
    return PublisherFaults::fails('scaffolding_stream_copy') ? false : \stream_copy_to_stream($from, $to);
}

function mkdir(string $directory, int $permissions = 0777): bool
{
    return !PublisherFaults::fails('scaffolding_mkdir') && \mkdir($directory, $permissions);
}

/** @return list<string>|false */
function scandir(string $directory): array|false
{
    return PublisherFaults::fails('scaffolding_scandir') ? false : \scandir($directory);
}

function unlink(string $filename): bool
{
    return \unlink($filename);
}

/** @param resource $stream */
function fclose(mixed $stream): bool
{
    PublisherFaults::record('scaffolding_fclose');

    return \fclose($stream);
}
