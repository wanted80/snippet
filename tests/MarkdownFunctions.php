<?php

declare(strict_types=1);

namespace Snippet\Markdown;

use Snippet\Tests\PublisherFaults;

function strcspn(string $string, string $characters, int $offset, ?int $length = null): int
{
    PublisherFaults::record('markdown_search');

    return \strcspn($string, $characters, $offset, $length);
}
