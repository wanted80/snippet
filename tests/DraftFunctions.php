<?php

declare(strict_types=1);

namespace Snippet\Authoring;

use Snippet\Tests\PublisherFaults;

/** @return resource|false */
function fopen(string $filename, string $mode)
{
    return PublisherFaults::fails('draft_fopen') ? false : \fopen($filename, $mode);
}

/** @param resource $stream */
function fwrite($stream, string $data): int|false
{
    return PublisherFaults::fails('draft_fwrite') ? false : \fwrite($stream, $data);
}

/** @param resource $stream */
function fclose($stream): bool
{
    return !PublisherFaults::fails('draft_fclose') && \fclose($stream);
}

function mkdir(string $directory, int $permissions = 0777): bool
{
    return !PublisherFaults::fails('draft_mkdir') && @\mkdir($directory, $permissions);
}
