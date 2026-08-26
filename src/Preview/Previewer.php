<?php

declare(strict_types=1);

namespace Snippet\Preview;

use Snippet\Exception\ContentException;
use SplFileObject;

/** Starts and owns the development preview lifecycle. */
interface Previewer
{
    /** @throws ContentException when the preview cannot be built or started */
    public function run(
        string $root,
        SplFileObject $stdout,
        SplFileObject $stderr,
        string $host = '127.0.0.1',
        int $port = 8080,
    ): int;
}
