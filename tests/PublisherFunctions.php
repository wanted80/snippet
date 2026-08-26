<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use Snippet\Tests\PublisherFaults;

function rename(string $from, string $to): bool
{
    return !PublisherFaults::fails('rename') && \rename($from, $to);
}

function file_put_contents(string $filename, string $data): int|false
{
    return match (PublisherFaults::outcome('file_put_contents')) {
        'fail' => false,
        'partial' => \file_put_contents($filename, mb_substr($data, 0, -1, '8bit')),
        default => \file_put_contents($filename, $data),
    };
}

function chmod(string $filename, int $permissions): bool
{
    return !PublisherFaults::fails('chmod') && \chmod($filename, $permissions);
}

function copy(string $from, string $to): bool
{
    return !PublisherFaults::fails('copy') && \copy($from, $to);
}

function filesize(string $filename): int|false
{
    return PublisherFaults::fails('filesize') ? false : \filesize($filename);
}

/** @return resource|false */
function fopen(string $filename, string $mode): mixed
{
    return PublisherFaults::fails('publishing_fopen') ? false : \fopen($filename, $mode);
}

/**
 * @param resource $stream
 * @param positive-int $length
 */
function fread(mixed $stream, int $length): string|false
{
    return PublisherFaults::fails('publishing_fread') ? false : \fread($stream, $length);
}

/** @param resource $stream */
function fwrite(mixed $stream, string $data): int|false
{
    return PublisherFaults::fails('publishing_fwrite') ? false : \fwrite($stream, $data);
}

/** @param resource $stream */
function rewind(mixed $stream): bool
{
    return !PublisherFaults::fails('publishing_rewind') && \rewind($stream);
}

/**
 * @param resource $stream
 * @param non-negative-int $size
 */
function ftruncate(mixed $stream, int $size): bool
{
    return !PublisherFaults::fails('publishing_ftruncate') && \ftruncate($stream, $size);
}

/** @param resource $stream */
function fclose(mixed $stream): bool
{
    return \fclose($stream);
}

function mkdir(string $directory, int $permissions, bool $recursive): bool
{
    return !PublisherFaults::fails('mkdir') && \mkdir($directory, $permissions, $recursive);
}

function unlink(string $filename): bool
{
    return !PublisherFaults::fails('unlink') && \unlink($filename);
}

/**
 * @param int<0, 2> $sortingOrder
 * @return list<string>|false
 */
function scandir(string $directory, int $sortingOrder = SCANDIR_SORT_ASCENDING): array|false
{
    return PublisherFaults::fails('scandir') ? false : \scandir($directory, $sortingOrder);
}

function rmdir(string $directory): bool
{
    return !PublisherFaults::fails('rmdir') && \rmdir($directory);
}

namespace Snippet\Support;

use Snippet\Tests\PublisherFaults;

/** @return resource|false */
function fopen(string $filename, string $mode): mixed
{
    return PublisherFaults::fails('support_fopen') ? false : \fopen($filename, $mode);
}

/**
 * @param resource $stream
 * @param positive-int $length
 */
function fread(mixed $stream, int $length): string|false
{
    return PublisherFaults::fails('support_fread') ? false : \fread($stream, $length);
}
